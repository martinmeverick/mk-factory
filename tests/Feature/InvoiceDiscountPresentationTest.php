<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Money\Money;
use App\Domain\Pdf\InvoicePdfDataFactory;
use App\Domain\Pdf\InvoicePdfLine;
use App\Domain\Pdf\InvoicePdfRenderer;
use App\Enums\VatRegime;
use App\Models\Contact;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

final class InvoiceDiscountPresentationTest extends TestCase
{
    /** Unsaved models with all mapper relations loaded: no database writes. */
    private function invoice(bool $vat = true, bool $margin = false, string $type = 'percent'): IssuedInvoice
    {
        $organization = new Organization(['name' => 'Dodavatel', 'logo_path' => null]);
        $organization->setRelation('settings', new OrganizationSettings);
        $invoice = new IssuedInvoice([
            'issue_date' => '2026-09-25', 'due_date' => '2026-10-09',
            'vat_regime' => $margin ? VatRegime::UsedGoodsMargin : VatRegime::Standard,
            'subtotal_minor' => $vat && ! $margin ? 90000 : 108900,
            'total_minor' => 108900, 'discount_type' => $type,
            'discount_value' => $type === 'percent' ? '10.00' : '121.00',
            'discount_total_minor' => 12100,
        ]);
        $item = new IssuedInvoiceItem([
            'description' => 'Telefon', 'quantity' => '1.000', 'unit' => 'ks',
            'unit_price_minor' => $vat && ! $margin ? 100000 : 121000,
            'vat_rate' => $vat && ! $margin ? '21.00' : null,
            'line_subtotal_minor' => $vat && ! $margin ? 90000 : 108900,
            'line_vat_minor' => $vat && ! $margin ? 18900 : 0,
            'line_total_minor' => 108900, 'line_discount_minor' => 12100,
            'acquisition_unit_price_minor' => 87654,
            'line_margin_gross_minor' => 21246, 'line_margin_vat_minor' => 3687,
        ]);
        $invoice->setRelation('items', new Collection([$item]));
        $invoice->setRelation('organization', $organization);
        $invoice->setRelation('contact', new Contact(['name' => 'Odběratel']));
        $invoice->setRelation('bankAccount', null);

        return $invoice;
    }

    public function test_mapper_and_pdf_show_original_prices_discount_and_net_vat_recap(): void
    {
        $data = app(InvoicePdfDataFactory::class)->fromInvoice($this->invoice());
        $line = $data->items[0];
        $this->assertSame(121000, $line->originalTotal()->getMinor());
        $this->assertSame(100000, $line->originalSubtotal()->getMinor());
        $this->assertSame(21000, $line->originalVat()->getMinor());
        $this->assertSame(90000, $data->vatBreakdown[0]['base']->getMinor());
        $this->assertSame(18900, $data->vatBreakdown[0]['vat']->getMinor());
        $this->assertSame(108900, $data->total->getMinor());
        $this->assertSame(121000, $data->originalTotal()->getMinor());

        $html = view('pdf.invoice', ['data' => $data])->render();
        $this->assertStringContainsString('Celkem před slevou', $html);
        $this->assertStringContainsString('Sleva 10 %', $html);
        $this->assertStringContainsString('−'.$data->discountTotal->formatCzech(), $html);
        $this->assertStringContainsString('Celkem po slevě', $html);
        $this->assertStringContainsString($line->originalSubtotal()->formatCzech(), $html);
        $this->assertStringContainsString($data->vatBreakdown[0]['base']->formatCzech(), $html);
        $this->assertStringStartsWith('%PDF', app(InvoicePdfRenderer::class)->render($data));
    }

    public function test_fixed_discount_non_payer_and_margin_pdf_do_not_expose_internal_values(): void
    {
        foreach ([false, true] as $margin) {
            $data = app(InvoicePdfDataFactory::class)->fromInvoice($this->invoice(false, $margin, 'fixed'));
            $this->assertSame('Sleva', $data->discountLabel());
            $this->assertSame(121000, $data->items[0]->originalTotal()->getMinor());
            $this->assertSame(0, $data->items[0]->originalVat()->getMinor());
            $html = view('pdf.invoice', ['data' => $data])->render();
            $this->assertStringContainsString('Celkem před slevou', $html);
            $this->assertStringNotContainsString('Rekapitulace DPH', $html);
            $this->assertStringNotContainsString(Money::fromMinor(87654)->formatCzech(), $html);
            $this->assertStringNotContainsString(Money::fromMinor(21246)->formatCzech(), $html);
            $this->assertStringNotContainsString(Money::fromMinor(3687)->formatCzech(), $html);
            $this->assertStringStartsWith('%PDF', app(InvoicePdfRenderer::class)->render($data));
        }
    }

    public function test_legacy_items_and_invoice_without_discount_keep_their_stored_totals(): void
    {
        $line = new InvoicePdfLine('Původní položka', '1', 'ks', Money::fromMinor(100), null,
            Money::fromMinor(100), Money::zero(), Money::fromMinor(100));
        $this->assertSame(100, $line->originalTotal()->getMinor());
        $this->assertSame(100, $line->originalSubtotal()->getMinor());

        $invoice = $this->invoice();
        $invoice->discount_type = 'none';
        $invoice->discount_value = '0';
        $invoice->discount_total_minor = 0;
        $invoice->items[0]->line_discount_minor = 0;
        $data = app(InvoicePdfDataFactory::class)->fromInvoice($invoice);
        $this->assertFalse($data->hasDiscount());
        $this->assertSame($data->total->getMinor(), $data->originalTotal()->getMinor());
        $this->assertStringNotContainsString('Celkem před slevou', view('pdf.invoice', ['data' => $data])->render());
    }

    public function test_form_keeps_old_discount_values_and_has_only_one_invoice_discount(): void
    {
        request()->setLaravelSession(app('session.store'));
        session()->flashInput(['discount_type' => 'percent', 'discount_value' => '12.50']);
        $html = view('invoices._form', [
            'invoice' => null, 'vatPayer' => false, 'defaults' => [],
            'errors' => new ViewErrorBag,
            'customers' => collect(), 'projects' => collect(), 'numberSeries' => collect(), 'bankAccounts' => collect(),
        ])->render();
        $this->assertSame(1, substr_count($html, 'name="discount_type"'));
        $this->assertSame(1, substr_count($html, 'name="discount_value"'));
        $this->assertStringContainsString('value="percent" selected', $html);
        $this->assertStringContainsString('max="100"', $html);
        $this->assertStringContainsString('value="12.50"', $html);
        $this->assertStringNotContainsString('[line_discount', $html);
    }
}
