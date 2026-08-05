<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Money\Money;
use App\Domain\Payments\QrPaymentImage;
use App\Domain\Payments\SpdPayload;
use App\Domain\Pdf\InvoicePdfDataFactory;
use App\Domain\Pdf\InvoicePdfRenderer;
use App\Domain\Tenancy\CurrentOrganization;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * NÁLEZ 4: QR platba a stavové informace v PDF.
 * NÁLEZ 9: historické PDF nesmí záviset na aktuálním logu organizace.
 *
 * Testuje se přes SKUTEČNÝ uložený Eloquent model a InvoicePdfDataFactory —
 * ručně sestavené DTO by chybu v mapování modelu obešlo.
 */
class InvoicePdfStateTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private IssuedInvoiceLifecycle $lifecycle;

    private InvoicePdfDataFactory $factory;

    private InvoiceNumberSeries $series;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);

        OrganizationSettings::factory()->create([
            'organization_id' => $this->organization->id,
            'vat_payer' => false,
        ]);

        BankAccount::factory()->create([
            'organization_id' => $this->organization->id,
            'iban' => 'CZ1801000000000123456789',
        ]);

        $this->series = InvoiceNumberSeries::factory()->create([
            'organization_id' => $this->organization->id,
            'prefix' => 'FV',
            'year' => 2026,
            'next_number' => 1,
            'number_format' => '{PREFIX}{YEAR}{NUMBER:4}',
        ]);

        $this->lifecycle = app(IssuedInvoiceLifecycle::class);
        $this->factory = app(InvoicePdfDataFactory::class);
    }

    private function draft(int $totalMinor = 1000000): IssuedInvoice
    {
        $invoice = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $this->organization->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $this->organization->id])->id,
            'number_series_id' => $this->series->id,
            'bank_account_id' => BankAccount::query()->firstOrFail()->id,
            'due_date' => '2026-08-15',
        ]);

        IssuedInvoiceItem::factory()->create([
            'organization_id' => $this->organization->id,
            'issued_invoice_id' => $invoice->id,
            'quantity' => '1',
            'unit_price_minor' => $totalMinor,
            'vat_rate' => null,
        ]);

        return $invoice;
    }

    private function issued(int $totalMinor = 1000000): IssuedInvoice
    {
        $invoice = $this->draft($totalMinor);
        $this->lifecycle->issue($invoice, CarbonImmutable::parse('2026-08-01'));

        return $invoice->fresh();
    }

    /**
     * Rozměry se liší schválně — stejně velké fake obrázky mají shodné
     * bajty a test „staré vs. nové logo“ by pak nic neprokázal.
     */
    private function uploadLogo(string $label, int $width = 120): void
    {
        $file = UploadedFile::fake()->image("logo-{$label}.png", $width, 60);
        $path = $file->store('logos/org-'.$this->organization->id, 'local');

        $this->organization->update(['logo_path' => $path]);
    }

    // ---------- NÁLEZ 4: QR podle stavu ----------

    public function test_draft_has_no_qr_code(): void
    {
        $data = $this->factory->fromInvoice($this->draft());

        $this->assertNull($data->qrDataUri);
    }

    public function test_issued_invoice_has_qr_for_the_full_amount(): void
    {
        $data = $this->factory->fromInvoice($this->issued(1000000));

        $this->assertNotNull($data->qrDataUri);
        $this->assertSame(1000000, $data->amountDue()->getMinor());
    }

    /**
     * Zadání: 10 000 celkem, 4 000 zaplaceno → QR na 6 000.
     */
    public function test_partially_paid_invoice_bills_only_the_remaining_amount(): void
    {
        $invoice = $this->issued(10000);
        $this->lifecycle->registerPayment($invoice, 4000, CarbonImmutable::parse('2026-08-02'));

        $data = $this->factory->fromInvoice($invoice->fresh());

        $this->assertNotNull($data->qrDataUri, 'Částečně uhrazená faktura má mít QR.');
        $this->assertSame(6000, $data->amountDue()->getMinor());
        $this->assertSame(4000, $data->paidAmount?->getMinor());
        $this->assertSame(10000, $data->total->getMinor());
        $this->assertSame('partially_paid', $data->status);

        // Ověření SKUTEČNĚ zakódované částky, ne jen údaje v DTO:
        // QR musí odpovídat obrázku pro 6 000 a lišit se od obrázku pro 10 000.
        $fresh = $invoice->fresh();

        $qrFor = fn (int $minor): string => QrPaymentImage::pngDataUri(SpdPayload::create(
            iban: 'CZ1801000000000123456789',
            amount: Money::fromMinor($minor, $fresh->currency),
            variableSymbol: $fresh->variable_symbol,
            message: "Faktura {$fresh->invoice_number}",
            dueDate: CarbonImmutable::parse($fresh->due_date),
        ));

        $this->assertSame($qrFor(6000), $data->qrDataUri, 'QR musí znít na zbývajících 6 000.');
        $this->assertNotSame($qrFor(10000), $data->qrDataUri, 'QR nesmí znít na původních 10 000.');
    }

    public function test_paid_invoice_has_no_qr_code(): void
    {
        $invoice = $this->issued(10000);
        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-02'));

        $data = $this->factory->fromInvoice($invoice->fresh());

        $this->assertNull($data->qrDataUri, 'Uhrazená faktura nesmí mít QR.');
        $this->assertTrue($data->isPaid());
    }

    public function test_cancelled_invoice_has_no_qr_code(): void
    {
        $invoice = $this->issued(10000);
        $this->lifecycle->cancel($invoice);

        $data = $this->factory->fromInvoice($invoice->fresh());

        $this->assertNull($data->qrDataUri, 'Stornovaná faktura nesmí mít QR.');
        $this->assertTrue($data->isCancelled());
    }

    public function test_remaining_amount_is_never_negative(): void
    {
        $invoice = $this->issued(10000);
        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-02'));

        $data = $this->factory->fromInvoice($invoice->fresh());

        $this->assertFalse($data->amountDue()->isNegative());
        $this->assertSame(0, $data->amountDue()->getMinor());
    }

    // ---------- NÁLEZ 4: stavové informace v PDF ----------

    public function test_partially_paid_pdf_shows_paid_and_remaining_amounts(): void
    {
        $invoice = $this->issued(10000);
        $this->lifecycle->registerPayment($invoice, 4000, CarbonImmutable::parse('2026-08-02'));

        $html = view('pdf.invoice', ['data' => $this->factory->fromInvoice($invoice->fresh())])->render();

        $this->assertStringContainsString('ČÁSTEČNĚ UHRAZENO', $html);
        $this->assertStringContainsString('Uhrazeno', $html);
        $this->assertStringContainsString('Zbývá uhradit', $html);
        $this->assertStringContainsString('Zbývá k úhradě', $html);
        $this->assertStringNotContainsString('Celkem k úhradě', $html);
    }

    public function test_paid_pdf_does_not_claim_the_amount_is_still_due(): void
    {
        $invoice = $this->issued(10000);
        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-02'));

        $html = view('pdf.invoice', ['data' => $this->factory->fromInvoice($invoice->fresh())])->render();

        $this->assertStringContainsString('UHRAZENO', $html);
        $this->assertStringContainsString('Celkem (uhrazeno)', $html);
        $this->assertStringNotContainsString('Celkem k úhradě', $html);
    }

    public function test_cancelled_pdf_does_not_claim_the_amount_is_still_due(): void
    {
        $invoice = $this->issued(10000);
        $this->lifecycle->cancel($invoice);

        $html = view('pdf.invoice', ['data' => $this->factory->fromInvoice($invoice->fresh())])->render();

        $this->assertStringContainsString('STORNOVÁNO', $html);
        $this->assertStringContainsString('Celkem (doklad stornován)', $html);
        $this->assertStringNotContainsString('Celkem k úhradě', $html);
    }

    public function test_issued_invoice_still_says_amount_due(): void
    {
        $html = view('pdf.invoice', ['data' => $this->factory->fromInvoice($this->issued())])->render();

        $this->assertStringContainsString('Celkem k úhradě', $html);
        $this->assertStringNotContainsString('STORNOVÁNO', $html);
    }

    public function test_pdf_renders_for_every_state(): void
    {
        $renderer = app(InvoicePdfRenderer::class);

        $paid = $this->issued(10000);
        $this->lifecycle->markPaid($paid, CarbonImmutable::parse('2026-08-02'));

        foreach ([$this->draft(), $this->issued(), $paid->fresh()] as $invoice) {
            $pdf = $renderer->render($this->factory->fromInvoice($invoice));

            $this->assertStringStartsWith('%PDF', $pdf);
        }
    }

    // ---------- NÁLEZ 9: historické logo ----------

    public function test_historical_invoice_keeps_its_own_logo_after_the_company_logo_changes(): void
    {
        $this->uploadLogo('a');
        $old = $this->issued();

        $this->assertNotNull($old->logo_snapshot_path, 'Vystavení musí logo zmrazit.');

        $logoA = $this->factory->fromInvoice($old)->logoDataUri;
        $this->assertNotNull($logoA);

        // Organizace nahraje jiné logo.
        $this->uploadLogo('b', 240);
        $new = $this->issued();

        $logoB = $this->factory->fromInvoice($new)->logoDataUri;
        $this->assertNotNull($logoB);

        // Stará faktura si drží své logo, nová používá to nové.
        $this->assertSame(
            $logoA,
            $this->factory->fromInvoice($old->fresh())->logoDataUri,
            'Historická faktura změnila logo.',
        );
        $this->assertNotSame($logoA, $logoB, 'Nová faktura musí použít nové logo.');
    }

    public function test_deleting_the_company_logo_does_not_break_historical_invoices(): void
    {
        $this->uploadLogo('a');
        $invoice = $this->issued();

        $logoBefore = $this->factory->fromInvoice($invoice)->logoDataUri;

        // Smazání aktuálního loga organizace.
        Storage::disk('local')->delete($this->organization->fresh()->logo_path);
        $this->organization->update(['logo_path' => null]);

        $this->assertSame(
            $logoBefore,
            $this->factory->fromInvoice($invoice->fresh())->logoDataUri,
            'Smazání firemního loga zničilo historický doklad.',
        );
    }

    public function test_logo_snapshot_path_is_relative_and_tenant_scoped(): void
    {
        $this->uploadLogo('a');
        $invoice = $this->issued();

        $path = $invoice->logo_snapshot_path;

        $this->assertStringStartsNotWith('/', $path, 'Cesta nesmí být absolutní.');
        $this->assertStringNotContainsString(base_path(), (string) $path);
        $this->assertStringStartsWith('invoice-logos/org-'.$this->organization->id.'/', (string) $path);
    }

    public function test_snapshot_of_another_organization_is_not_rendered(): void
    {
        $this->uploadLogo('a');
        $invoice = $this->issued();

        // Podvržená cesta na snapshot cizí organizace.
        IssuedInvoice::withoutGlobalScope('organization')
            ->whereKey($invoice->id)
            ->update(['logo_snapshot_path' => 'invoice-logos/org-999/invoice-1-abc.png']);

        $this->assertNull(
            $this->factory->fromInvoice($invoice->fresh())->logoDataUri,
            'Snapshot cizí organizace se nesmí vykreslit.',
        );
    }

    public function test_draft_uses_the_current_company_logo(): void
    {
        $this->uploadLogo('a');
        $draft = $this->draft();

        $this->assertNotNull($this->factory->fromInvoice($draft)->logoDataUri);
        $this->assertNull($draft->logo_snapshot_path, 'Koncept ještě snapshot nemá.');
    }
}
