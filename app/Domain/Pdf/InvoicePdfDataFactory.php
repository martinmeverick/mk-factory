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
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;

/**
 * Mapper Eloquent → InvoicePdfData. Vystavená faktura se mapuje ze snapshotů
 * (neměnnost), koncept z živých dat organizace a kontaktu.
 */
final class InvoicePdfDataFactory
{
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
            ))->all(),
            subtotal: Money::fromMinor((int) $invoice->subtotal_minor, $invoice->currency),
            vatBreakdown: $this->vatBreakdown($invoice),
            total: Money::fromMinor((int) $invoice->total_minor, $invoice->currency),
            vatPayer: $vatPayer,
            note: $invoice->note,
            footerText: $footerText,
            logoDataUri: $this->logoDataUri($organization->logo_path),
            qrDataUri: $this->qrDataUri($invoice, $bankAccount, $isDraft),
            vatRegime: $vatRegime,
        );
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

    private function logoDataUri(?string $logoPath): ?string
    {
        if ($logoPath === null || ! Storage::disk('local')->exists($logoPath)) {
            return null;
        }

        $contents = Storage::disk('local')->get($logoPath);
        $mime = Storage::disk('local')->mimeType($logoPath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    /**
     * QR Platba jen pro vystavené faktury s bankovním účtem a kladnou částkou.
     */
    private function qrDataUri(IssuedInvoice $invoice, ?array $bankAccount, bool $isDraft): ?string
    {
        if ($isDraft || $bankAccount === null || empty($bankAccount['iban']) || (int) $invoice->total_minor <= 0) {
            return null;
        }

        try {
            $payload = SpdPayload::create(
                iban: $bankAccount['iban'],
                amount: Money::fromMinor((int) $invoice->total_minor, $invoice->currency),
                variableSymbol: $invoice->variable_symbol,
                message: $invoice->invoice_number ? "Faktura {$invoice->invoice_number}" : null,
                dueDate: \Carbon\CarbonImmutable::parse($invoice->due_date),
            );

            return QrPaymentImage::pngDataUri($payload);
        } catch (\InvalidArgumentException) {
            // Nevalidní IBAN apod. — faktura se vygeneruje bez QR.
            return null;
        }
    }
}
