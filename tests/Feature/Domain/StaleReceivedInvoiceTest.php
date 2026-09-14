<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\InvoiceNotFound;
use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * RE-REVIEW, nález „stale instance může změnit a smazat uhrazenou přijatou
 * fakturu“: controller rozhodoval podle dřív načtené instance a model neměl
 * žádné guardy. Editovatelnost i smazatelnost teď rozhoduje AKTUÁLNÍ stav
 * (zamčený řádek v lifecycle, persistedStatus() v guardu) a stavová pole
 * mění výhradně lifecycle služba.
 */
class StaleReceivedInvoiceTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private ReceivedInvoiceLifecycle $lifecycle;

    protected function setUp(): void
    {
        parent::setUp();

        $this->organization = Organization::factory()->create();
        app(CurrentOrganization::class)->set($this->organization);

        $this->lifecycle = app(ReceivedInvoiceLifecycle::class);
    }

    /**
     * Dvě instance téže faktury; první ji uhradí, druhá zůstane zastaralá
     * (podle své paměti je stále ve stavu received).
     *
     * @return array{0: ReceivedInvoice, 1: ReceivedInvoice}
     */
    private function markPaidViaFirstInstance(): array
    {
        $invoice = ReceivedInvoice::factory()->create([
            'organization_id' => $this->organization->id,
            'total_minor' => 60500,
            'due_date' => '2026-08-15',
        ]);

        $stale = ReceivedInvoice::query()->findOrFail($invoice->id);
        $fresh = ReceivedInvoice::query()->findOrFail($invoice->id);

        $this->lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-05'));

        $this->assertSame(ReceivedInvoiceStatus::Received, $stale->status, 'Instance musí být opravdu zastaralá.');

        return [$stale, $fresh];
    }

    public function test_stale_instance_cannot_change_due_date_or_amount_after_mark_paid(): void
    {
        [$stale] = $this->markPaidViaFirstInstance();

        foreach (['due_date' => '2027-01-01', 'total_minor' => 1] as $attribute => $value) {
            $before = ReceivedInvoice::query()->findOrFail($stale->id)->getAttribute($attribute);

            try {
                $stale->update([$attribute => $value]);
                $this->fail("Zastaralá instance změnila {$attribute} uhrazené faktury.");
            } catch (ImmutableInvoiceViolation) {
                // očekáváno
            }

            $this->assertEquals(
                $before,
                ReceivedInvoice::query()->findOrFail($stale->id)->getAttribute($attribute),
                "Atribut {$attribute} se změnil v databázi.",
            );
        }
    }

    public function test_stale_instance_cannot_delete_a_paid_invoice(): void
    {
        [$stale] = $this->markPaidViaFirstInstance();

        try {
            $stale->delete();
            $this->fail('Zastaralá instance smazala uhrazenou fakturu.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertDatabaseHas('received_invoices', ['id' => $stale->id]);
    }

    public function test_stale_lifecycle_update_is_refused_after_mark_paid(): void
    {
        [$stale] = $this->markPaidViaFirstInstance();

        try {
            $this->lifecycle->updateDetails($stale, ['due_date' => '2027-01-01']);
            $this->fail('Lifecycle update zastaralé instance musí odmítnout uhrazenou fakturu.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertSame(
            '2026-08-15',
            ReceivedInvoice::query()->findOrFail($stale->id)->due_date->toDateString(),
        );
    }

    public function test_stale_lifecycle_delete_is_refused_after_mark_paid(): void
    {
        [$stale] = $this->markPaidViaFirstInstance();

        try {
            $this->lifecycle->delete($stale);
            $this->fail('Lifecycle delete zastaralé instance musí odmítnout uhrazenou fakturu.');
        } catch (InvalidStateTransition) {
            // očekáváno
        }

        $this->assertDatabaseHas('received_invoices', ['id' => $stale->id]);
        $this->assertSame(1, Payment::query()->where('payable_id', $stale->id)->count());
    }

    public function test_organization_id_cannot_be_changed(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        $foreign = Organization::factory()->create();

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->update(['organization_id' => $foreign->id]);
    }

    public function test_status_and_paid_at_cannot_be_written_directly(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        try {
            $invoice->update(['status' => ReceivedInvoiceStatus::Paid, 'paid_at' => now()]);
            $this->fail('Přímý zápis stavu paid musí být odmítnut.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $fresh = ReceivedInvoice::query()->findOrFail($invoice->id);
        $this->assertSame(ReceivedInvoiceStatus::Received, $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(0, Payment::query()->count(), 'Stav paid bez platby nesmí vzniknout.');
    }

    public function test_paid_invoice_cannot_be_reverted_to_a_previous_state(): void
    {
        [, $fresh] = $this->markPaidViaFirstInstance();

        $this->expectException(ImmutableInvoiceViolation::class);

        $fresh->update(['status' => ReceivedInvoiceStatus::Received]);
    }

    public function test_rejected_invoice_cannot_be_updated_directly(): void
    {
        $invoice = ReceivedInvoice::factory()->rejected()->create(['organization_id' => $this->organization->id]);

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->update(['note' => 'podvrh']);
    }

    public function test_editable_update_via_lifecycle_works_and_is_audited(): void
    {
        $invoice = ReceivedInvoice::factory()->create([
            'organization_id' => $this->organization->id,
            'due_date' => '2026-08-15',
        ]);

        $this->lifecycle->updateDetails($invoice, ['due_date' => '2026-09-01', 'note' => 'upraveno']);

        $fresh = ReceivedInvoice::query()->findOrFail($invoice->id);
        $this->assertSame('2026-09-01', $fresh->due_date->toDateString());
        $this->assertSame('upraveno', $fresh->note);
        $this->assertSame(
            1,
            AuditLog::query()
                ->where('action', 'invoice.updated')
                ->where('organization_id', $this->organization->id)
                ->count(),
        );
    }

    public function test_update_details_rejects_unknown_attributes_and_writes_nothing(): void
    {
        $invoice = ReceivedInvoice::factory()->create([
            'organization_id' => $this->organization->id,
            'due_date' => '2026-08-15',
        ]);

        foreach (['organization_id' => 999, 'status' => 'paid', 'paid_at' => now(), 'id' => 999] as $attribute => $value) {
            try {
                $this->lifecycle->updateDetails($invoice, ['due_date' => '2027-01-01', $attribute => $value]);
                $this->fail("Atribut {$attribute} musí být odmítnut.");
            } catch (InvalidArgumentException) {
                // očekáváno
            }
        }

        $this->assertSame(
            '2026-08-15',
            ReceivedInvoice::query()->findOrFail($invoice->id)->due_date->toDateString(),
            'Legitimní část změny se nesmí zapsat.',
        );
    }

    public function test_lifecycle_delete_removes_invoice_attachments_and_files(): void
    {
        Storage::fake('local');

        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        Storage::disk('local')->put('attachments/org-'.$this->organization->id.'/doklad.pdf', 'PDF');
        $attachment = ReceivedInvoiceAttachment::factory()->create([
            'organization_id' => $this->organization->id,
            'received_invoice_id' => $invoice->id,
            'stored_path' => 'attachments/org-'.$this->organization->id.'/doklad.pdf',
        ]);

        $this->lifecycle->delete($invoice);

        $this->assertDatabaseMissing('received_invoices', ['id' => $invoice->id]);
        $this->assertDatabaseMissing('received_invoice_attachments', ['id' => $attachment->id]);
        Storage::disk('local')->assertMissing('attachments/org-'.$this->organization->id.'/doklad.pdf');
        $this->assertSame(1, AuditLog::query()->where('action', 'invoice.deleted')->count());
    }

    public function test_attachments_of_a_paid_invoice_are_frozen(): void
    {
        Storage::fake('local');

        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);
        $attachment = ReceivedInvoiceAttachment::factory()->create([
            'organization_id' => $this->organization->id,
            'received_invoice_id' => $invoice->id,
        ]);

        $this->lifecycle->markPaid($invoice, CarbonImmutable::parse('2026-08-05'));

        try {
            $attachment->delete();
            $this->fail('Přílohu uhrazené faktury nesmí jít smazat.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        try {
            ReceivedInvoiceAttachment::factory()->create([
                'organization_id' => $this->organization->id,
                'received_invoice_id' => $invoice->id,
            ]);
            $this->fail('K uhrazené faktuře nesmí jít přidat příloha.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertSame(1, ReceivedInvoiceAttachment::query()->count());
    }

    public function test_foreign_organization_context_cannot_touch_the_invoice(): void
    {
        $invoice = ReceivedInvoice::factory()->create(['organization_id' => $this->organization->id]);

        app(CurrentOrganization::class)->set(Organization::factory()->create());

        try {
            $this->lifecycle->updateDetails($invoice, ['note' => 'podvrh']);
            $this->fail('Cizí organizace upravila fakturu.');
        } catch (InvoiceNotFound) {
            // očekáváno
        }

        try {
            $this->lifecycle->delete($invoice);
            $this->fail('Cizí organizace smazala fakturu.');
        } catch (InvoiceNotFound) {
            // očekáváno
        }

        $this->assertDatabaseHas('received_invoices', ['id' => $invoice->id]);
    }

    /**
     * Statická pojistka jako u vydaných faktur: nová veřejná mutační metoda
     * modelu shodí test a vynutí vědomé rozhodnutí v review.
     */
    public function test_received_invoice_declares_no_unexpected_public_method(): void
    {
        $reflection = new \ReflectionClass(ReceivedInvoice::class);

        $declared = array_values(array_map(
            fn (\ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(\ReflectionMethod::IS_PUBLIC),
                fn (\ReflectionMethod $method): bool => $method->getFileName() === $reflection->getFileName(),
            ),
        ));

        sort($declared);

        $safe = [
            'attachments', 'contact', 'isFinal', 'isFinalStatus', 'isOverdue',
            'payments', 'persistedStatus', 'project', 'scopeOverdue', 'totalMoney',
        ];

        $this->assertSame([], array_diff($declared, $safe));
    }
}
