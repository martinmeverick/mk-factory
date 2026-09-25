<?php

declare(strict_types=1);

namespace App\Domain\Pdf;

use App\Domain\Money\Money;
use App\Domain\Payments\QrPaymentImage;
use App\Domain\Payments\SpdPayload;
use App\Enums\IssuedInvoiceStatus;
use App\Enums\VatRegime;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Mapper Eloquent → InvoicePdfData. Vystavená faktura se mapuje ze snapshotů
 * (neměnnost), koncept z živých dat organizace a kontaktu.
 */
final class InvoicePdfDataFactory
{
    public function __construct(
        private readonly InvoiceLogoSnapshotStore $logoSnapshots,
    ) {}

    public function fromInvoice(IssuedInvoice $invoice): InvoicePdfData
    {
        $invoice->loadMissing(['items', 'contact', 'bankAccount', 'organization.settings']);

        $isDraft = $invoice->status === IssuedInvoiceStatus::Draft;
        $organization = $invoice->organization;

        $supplier = $this->normalize((! $isDraft && $invoice->supplier_snapshot)
            ? $invoice->supplier_snapshot
            : [
                'name' => $organization->name,
                'ico' => $organization->ico,
                'dic' => $organization->dic,
                'street' => $organization->street,
                'city' => $organization->city,
                'zip' => $organization->zip,
                'country' => $organization->country,
                'email' => $organization->email,
                'phone' => $organization->phone,
                'website' => $organization->website,
            ], ['name', 'ico', 'dic', 'street', 'city', 'zip', 'country', 'email', 'phone', 'website']);

        $customer = $this->normalize((! $isDraft && $invoice->customer_snapshot)
            ? $invoice->customer_snapshot
            : [
                'name' => $invoice->contact?->name,
                'ico' => $invoice->contact?->ico,
                'dic' => $invoice->contact?->dic,
                'street' => $invoice->contact?->street,
                'city' => $invoice->contact?->city,
                'zip' => $invoice->contact?->zip,
                'country' => $invoice->contact?->country,
            ], ['name', 'ico', 'dic', 'street', 'city', 'zip', 'country']);

        $bankAccount = (! $isDraft && $invoice->bank_account_snapshot)
            ? $invoice->bank_account_snapshot
            : ($invoice->bankAccount ? [
                'account_number' => $invoice->bankAccount->account_number,
                'bank_code' => $invoice->bankAccount->bank_code,
                'iban' => $invoice->bankAccount->iban,
                'bic' => $invoice->bankAccount->bic,
            ] : null);

        $items = $invoice->items->sortBy('position')->values();
        $vatRegime = $invoice->vatRegime();

        // Zvláštní režim - použité zboží: dodavatel JE plátce (daňový doklad),
        // i když položky nenesou sazbu DPH. Rozhoduje uložený režim faktury,
        // nikdy aktuální nastavení organizace.
        $vatPayer = $vatRegime === VatRegime::UsedGoodsMargin
            || $items->contains(fn (IssuedInvoiceItem $item) => $item->vat_rate !== null);

        $footerText = $isDraft
            ? $organization->settings?->invoice_footer_text
            : $invoice->footer_text;

        return new InvoicePdfData(
            supplier: $supplier,
            customer: $customer,
            invoiceNumber: $invoice->invoice_number,
            variableSymbol: $invoice->variable_symbol,
            issueDate: new DateTimeImmutable($invoice->issue_date->toDateString()),
            dueDate: new DateTimeImmutable($invoice->due_date->toDateString()),
            taxDate: $invoice->tax_date ? new DateTimeImmutable($invoice->tax_date->toDateString()) : null,
            bankAccount: $bankAccount,
            items: $items->map(fn (IssuedInvoiceItem $item) => new InvoicePdfLine(
                description: $item->description,
                quantity: (string) $item->quantity,
                unit: $item->unit,
                unitPrice: Money::fromMinor((int) $item->unit_price_minor, $invoice->currency),
                vatRate: $item->vat_rate !== null ? (string) $item->vat_rate : null,
                lineSubtotal: Money::fromMinor((int) $item->line_subtotal_minor, $invoice->currency),
                lineVat: Money::fromMinor((int) $item->line_vat_minor, $invoice->currency),
                lineTotal: Money::fromMinor((int) $item->line_total_minor, $invoice->currency),
                lineDiscount: Money::fromMinor((int) ($item->line_discount_minor ?? 0), $invoice->currency),
            ))->all(),
            subtotal: Money::fromMinor((int) $invoice->subtotal_minor, $invoice->currency),
            vatBreakdown: $this->vatBreakdown($invoice),
            total: Money::fromMinor((int) $invoice->total_minor, $invoice->currency),
            vatPayer: $vatPayer,
            note: $invoice->note,
            footerText: $footerText,
            logoDataUri: $this->logoDataUri($invoice, $organization, $isDraft),
            qrDataUri: $this->qrDataUri($invoice, $bankAccount),
            status: $invoice->status->value,
            paidAmount: Money::fromMinor((int) $invoice->paid_amount_minor, $invoice->currency),
            remainingAmount: $this->remainingAmount($invoice),
            vatRegime: $vatRegime,
            discountType: (string) ($invoice->discount_type ?? 'none'),
            discountValue: (string) ($invoice->discount_value ?? '0'),
            discountTotal: Money::fromMinor((int) ($invoice->discount_total_minor ?? 0), $invoice->currency),
        );
    }

    /**
     * Zbývající částka nikdy není záporná (přeplatek se nevrací jako mínus).
     */
    private function remainingAmount(IssuedInvoice $invoice): Money
    {
        $remaining = (int) $invoice->total_minor - (int) $invoice->paid_amount_minor;

        return Money::fromMinor(max(0, $remaining), $invoice->currency);
    }

    /**
     * Doplní chybějící klíče (starší snapshoty nemusí obsahovat vše,
     * co šablona očekává).
     *
     * @param  array<string, mixed>  $data
     * @param  list<string>  $keys
     * @return array<string, ?string>
     */
    private function normalize(array $data, array $keys): array
    {
        $normalized = [];

        foreach ($keys as $key) {
            $normalized[$key] = $data[$key] ?? null;
        }

        return $normalized;
    }

    /**
     * @return list<array{rate: string, base: Money, vat: Money}>
     */
    private function vatBreakdown(IssuedInvoice $invoice): array
    {
        return $invoice->items
            ->filter(fn (IssuedInvoiceItem $item) => $item->vat_rate !== null)
            ->groupBy(fn (IssuedInvoiceItem $item) => (string) $item->vat_rate)
            ->map(fn ($group, string $rate) => [
                'rate' => $rate,
                'base' => Money::fromMinor((int) $group->sum('line_subtotal_minor'), $invoice->currency),
                'vat' => Money::fromMinor((int) $group->sum('line_vat_minor'), $invoice->currency),
            ])
            ->sortByDesc(fn (array $row) => (float) $row['rate'])
            ->values()
            ->all();
    }

    /**
     * Koncept použije aktuální logo organizace, vystavená faktura svůj
     * snapshot — pozdější změna či smazání firemního loga nesmí změnit
     * historický doklad. Snapshot musí patřit téže organizaci.
     */
    private function logoDataUri(IssuedInvoice $invoice, Organization $organization, bool $isDraft): ?string
    {
        $path = $isDraft ? $organization->logo_path : $invoice->logo_snapshot_path;

        if ($path === null) {
            return null;
        }

        if (! $isDraft && ! $this->logoSnapshots->belongsToOrganization($path, (int) $invoice->organization_id)) {
            return null;
        }

        if (! Storage::disk('local')->exists($path)) {
            return null;
        }

        $contents = Storage::disk('local')->get($path);
        $mime = Storage::disk('local')->mimeType($path) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    /**
     * QR Platba podle stavu dokladu (viz INVOICE_LIFECYCLE.md):
     * draft / paid / cancelled → žádné QR;
     * issued, overdue, partially_paid → QR na ZBÝVAJÍCÍ částku.
     */
    private function qrDataUri(IssuedInvoice $invoice, ?array $bankAccount): ?string
    {
        $payable = [IssuedInvoiceStatus::Issued, IssuedInvoiceStatus::PartiallyPaid];

        if (! in_array($invoice->status, $payable, true)) {
            return null;
        }

        if ($bankAccount === null || empty($bankAccount['iban'])) {
            return null;
        }

        $remaining = $this->remainingAmount($invoice);

        if (! $remaining->isPositive()) {
            return null;
        }

        try {
            $payload = SpdPayload::create(
                iban: $bankAccount['iban'],
                amount: $remaining,
                variableSymbol: $invoice->variable_symbol,
                message: $invoice->invoice_number ? "Faktura {$invoice->invoice_number}" : null,
                dueDate: CarbonImmutable::parse($invoice->due_date),
            );

            return QrPaymentImage::pngDataUri($payload);
        } catch (\InvalidArgumentException) {
            // Nevalidní IBAN apod. — faktura se vygeneruje bez QR.
            return null;
        }
    }
}
