<?php

declare(strict_types=1);

namespace Tests\Feature\Pdf;

use App\Domain\Invoicing\IssuedInvoiceLifecycle;
use App\Domain\Pdf\InvoiceLogoSnapshotStore;
use App\Domain\Pdf\LogoSnapshotFailed;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\IssuedInvoiceStatus;
use App\Models\BankAccount;
use App\Models\Contact;
use App\Models\InvoiceNumberSeries;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\OrganizationSettings;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * RE-REVIEW, nález „snapshot loga není atomický s DB transakcí a ignoruje
 * chybu zápisu“: put() se dřív nekontrolovalo a soubor vzniklý před
 * rollbackem zůstával osiřelý.
 *
 * Kontrakt teď je: filesystem a MariaDB SPOLEČNOU transakci nemají, drží se
 * kompenzační konzistence — zápis souboru se ověřuje (LogoSnapshotFailed
 * místo tichého pokračování), cesta se ukládá ve stejné DB transakci, která
 * vyžaduje existenci souboru, a po rollbacku se soubor vytvořený daným
 * pokusem kompenzačně smaže. Vystavená faktura tak nikdy neodkazuje na
 * chybějící soubor; po pádu procesu může zůstat nanejvýš osiřelý soubor
 * bez odkazu (viz PDF_AND_QR.md).
 */
class LogoSnapshotConsistencyTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    private function draftWithLogo(string $logoContents = 'PNG-DATA'): IssuedInvoice
    {
        Storage::disk('local')->put('logos/org-'.$this->organization->id.'/logo.png', $logoContents);
        $this->organization->update(['logo_path' => 'logos/org-'.$this->organization->id.'/logo.png']);

        $invoice = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $this->organization->id,
            'contact_id' => Contact::factory()->create(['organization_id' => $this->organization->id])->id,
            'number_series_id' => InvoiceNumberSeries::factory()->create([
                'organization_id' => $this->organization->id,
                // Unikátní prefix — test zakládá víc řad v jedné organizaci.
                'prefix' => 'F'.fake()->unique()->numerify('##'),
            ])->id,
            'bank_account_id' => BankAccount::query()->firstOrFail()->id,
            'due_date' => '2026-08-15',
        ]);

        IssuedInvoiceItem::factory()->create([
            'organization_id' => $this->organization->id,
            'issued_invoice_id' => $invoice->id,
            'unit_price_minor' => 10000,
            'quantity' => '1',
            'vat_rate' => null,
        ]);

        return $invoice;
    }

    /**
     * Koncept bez jakéhokoli nastaveného loga — organizace ho prostě nemá.
     */
    private function draftWithoutLogo(): IssuedInvoice
    {
        $invoice = $this->draftWithLogo();

        Storage::disk('local')->delete('logos/org-'.$this->organization->id.'/logo.png');
        $this->organization->update(['logo_path' => null]);

        return $invoice;
    }

    private function expectedSnapshotPath(IssuedInvoice $invoice, string $logoContents): string
    {
        return sprintf(
            'invoice-logos/org-%d/invoice-%d-%s.png',
            $this->organization->id,
            $invoice->id,
            substr(hash('sha256', $logoContents), 0, 16),
        );
    }

    /**
     * Simulace selhání DB po zápisu snapshotu: `updated` event vystavované
     * faktury spadne uvnitř transakce — po vytvoření souboru, před commitem.
     */
    private function failNextIssuedInvoiceUpdate(): void
    {
        IssuedInvoice::updated(function (): void {
            throw new RuntimeException('Simulovaná chyba DB před commitem.');
        });
    }

    /**
     * Po neúspěšném vystavení nesmí zůstat ŽÁDNÝ efekt: stav draft, žádné
     * číslo, nespotřebovaná řada, žádný audit vystavení a žádný soubor.
     */
    private function assertNothingIssued(IssuedInvoice $invoice, string $expectedSnapshotPath): void
    {
        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);

        $this->assertSame(IssuedInvoiceStatus::Draft, $fresh->status);
        $this->assertNull($fresh->invoice_number);
        $this->assertNull($fresh->logo_snapshot_path);
        $this->assertSame(
            1,
            (int) InvoiceNumberSeries::query()->findOrFail($invoice->number_series_id)->next_number,
            'Neúspěšné vystavení nesmí spotřebovat číslo řady.',
        );
        $this->assertFalse(
            Storage::disk('local')->exists($expectedSnapshotPath),
            'Po neúspěchu nesmí zůstat soubor snapshotu.',
        );
    }

    public function test_failed_put_rolls_back_the_issue(): void
    {
        $fake = Storage::fake('local');
        $invoice = $this->draftWithLogo();

        $failing = Mockery::mock($fake)->makePartial();
        $failing->shouldReceive('put')->andReturn(false);
        Storage::set('local', $failing);

        try {
            app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Neúspěšný zápis snapshotu musí vystavení zastavit.');
        } catch (LogoSnapshotFailed) {
            // očekáváno
        }

        Storage::set('local', $fake);

        $this->assertNothingIssued($invoice, $this->expectedSnapshotPath($invoice, 'PNG-DATA'));
    }

    public function test_filesystem_exception_rolls_back_the_issue(): void
    {
        $fake = Storage::fake('local');
        $invoice = $this->draftWithLogo();

        $failing = Mockery::mock($fake)->makePartial();
        $failing->shouldReceive('put')->andThrow(new RuntimeException('Disk plný.'));
        Storage::set('local', $failing);

        try {
            app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Výjimka filesystemu musí vystavení zastavit.');
        } catch (LogoSnapshotFailed $e) {
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        Storage::set('local', $fake);

        $this->assertNothingIssued($invoice, $this->expectedSnapshotPath($invoice, 'PNG-DATA'));
    }

    public function test_failure_after_snapshot_but_before_commit_cleans_the_file_up(): void
    {
        Storage::fake('local');
        $invoice = $this->draftWithLogo();
        $snapshotPath = $this->expectedSnapshotPath($invoice, 'PNG-DATA');

        // `updated` event modelu běží uvnitř transakce AŽ PO vytvoření
        // souboru — jeho pád je deterministická simulace „DB selhala po
        // zápisu na disk, před commitem“.
        $this->failNextIssuedInvoiceUpdate();

        try {
            app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Pád po zápisu souboru musí vystavení odvalit.');
        } catch (RuntimeException) {
            // očekáváno
        }

        $this->assertNothingIssued($invoice, $snapshotPath);
    }

    /**
     * RE-REVIEW, nález 6: NENAKONFIGUROVANÉ logo je legitimní stav —
     * faktura se vystaví bez snapshotu.
     */
    public function test_organization_without_a_logo_issues_normally(): void
    {
        Storage::fake('local');
        $invoice = $this->draftWithoutLogo();

        app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);

        $this->assertSame(IssuedInvoiceStatus::Issued, $fresh->status);
        $this->assertNotNull($fresh->invoice_number);
        $this->assertNull($fresh->logo_snapshot_path, 'Bez loga nevzniká snapshot.');
    }

    /**
     * RE-REVIEW, nález 6: logo NAKONFIGUROVANÉ, ale soubor chybí — dřív se
     * tiše vrátilo null a faktura se vystavila bez loga (a spotřebovala
     * číslo). Teď je to tvrdá chyba.
     */
    public function test_configured_but_missing_logo_stops_issuing(): void
    {
        Storage::fake('local');
        $invoice = $this->draftWithLogo();

        // Logo zmizí z disku, ale v nastavení organizace zůstává.
        Storage::disk('local')->delete('logos/org-'.$this->organization->id.'/logo.png');

        try {
            app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Chybějící nakonfigurované logo musí vystavení zastavit.');
        } catch (LogoSnapshotFailed $e) {
            $this->assertStringContainsString('logo', mb_strtolower($e->getMessage()));
        }

        $this->assertNothingIssued($invoice, $this->expectedSnapshotPath($invoice, 'PNG-DATA'));
    }

    public function test_unreadable_configured_logo_stops_issuing(): void
    {
        $fake = Storage::fake('local');
        $invoice = $this->draftWithLogo();

        $failing = Mockery::mock($fake)->makePartial();
        $failing->shouldReceive('get')->andThrow(new RuntimeException('I/O chyba.'));
        Storage::set('local', $failing);

        try {
            app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
            $this->fail('Nečitelné logo musí vystavení zastavit.');
        } catch (LogoSnapshotFailed $e) {
            $this->assertInstanceOf(RuntimeException::class, $e->getPrevious());
        }

        Storage::set('local', $fake);

        $this->assertNothingIssued($invoice, $this->expectedSnapshotPath($invoice, 'PNG-DATA'));
    }

    /**
     * Cesta mimo adresář vlastní organizace (nebo s traversalem) se nesmí
     * zkopírovat — Flysystem `..` normalizuje, takže samotný prefix nestačí.
     */
    public function test_unsafe_logo_path_stops_issuing(): void
    {
        foreach ([
            'logos/org-'.($this->organization->id + 1).'/cizi.png',
            'logos/org-'.$this->organization->id.'/../org-'.($this->organization->id + 1).'/cizi.png',
            '/etc/passwd',
            'attachments/org-'.$this->organization->id.'/faktura.pdf',
        ] as $unsafePath) {
            Storage::fake('local');
            $invoice = $this->draftWithLogo();

            Storage::disk('local')->put($unsafePath, 'CIZI-DATA');
            $this->organization->update(['logo_path' => $unsafePath]);

            try {
                app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
                $this->fail("Nebezpečná cesta {$unsafePath} musí vystavení zastavit.");
            } catch (LogoSnapshotFailed) {
                // očekáváno
            }

            $this->assertSame(
                IssuedInvoiceStatus::Draft,
                IssuedInvoice::query()->findOrFail($invoice->id)->status,
                "Faktura musí zůstat konceptem pro cestu {$unsafePath}.",
            );
        }
    }

    public function test_issued_invoice_never_references_a_missing_snapshot(): void
    {
        Storage::fake('local');
        $invoice = $this->draftWithLogo();

        app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);
        $store = app(InvoiceLogoSnapshotStore::class);

        $this->assertSame(IssuedInvoiceStatus::Issued, $fresh->status);
        $this->assertNotNull($fresh->logo_snapshot_path, 'Organizace s logem musí mít snapshot.');
        $this->assertTrue($store->exists($fresh->logo_snapshot_path), 'Uložená cesta musí vést na existující soubor.');
    }

    public function test_changing_the_company_logo_keeps_the_historical_snapshot(): void
    {
        Storage::fake('local');
        $invoice = $this->draftWithLogo('LOGO-A');

        app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
        $snapshotPath = IssuedInvoice::query()->findOrFail($invoice->id)->logo_snapshot_path;

        // Organizace nahraje jiné logo i pod jinou cestou.
        Storage::disk('local')->put('logos/org-'.$this->organization->id.'/nove.png', 'LOGO-B');
        $this->organization->update(['logo_path' => 'logos/org-'.$this->organization->id.'/nove.png']);

        $this->assertSame(
            $snapshotPath,
            IssuedInvoice::query()->findOrFail($invoice->id)->logo_snapshot_path,
            'Cesta snapshotu historického dokladu se nemění.',
        );
        $this->assertSame('LOGO-A', Storage::disk('local')->get($snapshotPath), 'Obsah snapshotu zůstává původní.');
    }

    public function test_snapshot_of_another_organization_is_rejected_by_tenant_check(): void
    {
        Storage::fake('local');
        $invoice = $this->draftWithLogo();

        app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));
        $snapshotPath = IssuedInvoice::query()->findOrFail($invoice->id)->logo_snapshot_path;

        $store = app(InvoiceLogoSnapshotStore::class);

        $this->assertTrue($store->belongsToOrganization($snapshotPath, $this->organization->id));
        $this->assertFalse(
            $store->belongsToOrganization($snapshotPath, $this->organization->id + 1),
            'Cizí organizace nesmí snapshot použít.',
        );
    }

    /**
     * Integrační ověření nad SKUTEČNÝM lokálním diskem (žádný Storage::fake):
     * reálný LocalFilesystemAdapter v dočasném adresáři — úspěšná cesta
     * i kompenzační úklid po rollbacku.
     */
    public function test_real_local_disk_snapshot_and_rollback_cleanup(): void
    {
        $root = sys_get_temp_dir().'/mkf-logo-integration-'.uniqid();
        File::makeDirectory($root, 0755, true);
        config(['filesystems.disks.local.root' => $root]);

        try {
            $invoice = $this->draftWithLogo('REAL-DISK-LOGO');
            $snapshotPath = $this->expectedSnapshotPath($invoice, 'REAL-DISK-LOGO');

            app(IssuedInvoiceLifecycle::class)->issue($invoice, CarbonImmutable::parse('2026-08-01'));

            $this->assertFileExists($root.'/'.$snapshotPath, 'Snapshot musí ležet na skutečném disku.');
            $this->assertSame(
                $snapshotPath,
                IssuedInvoice::query()->findOrFail($invoice->id)->logo_snapshot_path,
            );

            // Druhá faktura: DB spadne po zápisu souboru → rollback musí
            // soubor z reálného disku uklidit.
            $second = $this->draftWithLogo('REAL-DISK-LOGO');
            $secondSnapshotPath = $this->expectedSnapshotPath($second, 'REAL-DISK-LOGO');

            $this->failNextIssuedInvoiceUpdate();

            try {
                app(IssuedInvoiceLifecycle::class)->issue($second, CarbonImmutable::parse('2026-08-01'));
                $this->fail('Pád DB po zápisu souboru musí vystavení odvalit.');
            } catch (RuntimeException) {
                // očekáváno
            }

            $this->assertFileDoesNotExist($root.'/'.$secondSnapshotPath, 'Rollback musí soubor uklidit.');
            $this->assertFileExists($root.'/'.$snapshotPath, 'Úklid nesmí sáhnout na snapshot cizí (úspěšné) faktury.');
        } finally {
            File::deleteDirectory($root);
        }
    }
}
