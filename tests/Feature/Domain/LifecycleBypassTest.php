<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Invoicing\ImmutablePaymentViolation;
use App\Enums\IssuedInvoiceStatus;
use App\Models\AuditLog;
use App\Models\IssuedInvoice;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

/**
 * RE-REVIEW, nález „veřejné modelové metody obcházejí lifecycle“:
 * applyIssued()/applyPaymentState()/applyCancelled() byly veřejné a šlo
 * jimi (i obyčejným update()) měnit stav, částku úhrady či storno bez
 * validace, čísla, plateb, auditu a zámku.
 *
 * Testy neprokazují jen nepřítomnost názvů metod — prokazují nepřítomnost
 * FUNKČNÍ veřejné cesty: každý pokus o přímý zápis lifecycle pole musí
 * skončit výjimkou a beze změny v databázi.
 */
class LifecycleBypassTest extends TestCase
{
    use RefreshDatabase;

    public function test_draft_cannot_be_switched_to_issued_by_a_direct_update(): void
    {
        $draft = IssuedInvoice::factory()->draft()->create();

        try {
            $draft->update(['status' => IssuedInvoiceStatus::Issued]);
            $this->fail('Přímá změna stavu draft → issued musí být odmítnuta.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $fresh = IssuedInvoice::query()->findOrFail($draft->id);
        $this->assertSame(IssuedInvoiceStatus::Draft, $fresh->status);
        $this->assertNull($fresh->invoice_number);
        $this->assertSame(0, AuditLog::query()->where('action', 'invoice.issued')->count());
    }

    public function test_issued_state_cannot_be_set_without_a_number(): void
    {
        $draft = IssuedInvoice::factory()->draft()->create();

        try {
            // forceFill + save je nejnižší veřejná Eloquent cesta — musí
            // narazit na guard stejně jako update().
            $draft->forceFill(['status' => IssuedInvoiceStatus::Issued, 'issued_at' => now()])->save();
            $this->fail('Vystavený stav bez čísla musí být odmítnut.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $fresh = IssuedInvoice::query()->findOrFail($draft->id);
        $this->assertSame(IssuedInvoiceStatus::Draft, $fresh->status);
        $this->assertNull($fresh->invoice_number);
        $this->assertNull($fresh->issued_at);
    }

    public function test_paid_amount_cannot_be_written_directly(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create(['total_minor' => 121000]);

        try {
            $invoice->update(['paid_amount_minor' => 121000]);
            $this->fail('Přímý zápis paid_amount_minor musí být odmítnut.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertSame(0, (int) IssuedInvoice::query()->findOrFail($invoice->id)->paid_amount_minor);
    }

    public function test_paid_status_cannot_be_set_without_a_payment_row(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();

        try {
            $invoice->update(['status' => IssuedInvoiceStatus::Paid, 'paid_at' => now()]);
            $this->fail('Stav paid bez platby musí být odmítnut.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);
        $this->assertSame(IssuedInvoiceStatus::Issued, $fresh->status);
        $this->assertNull($fresh->paid_at);
        $this->assertSame(0, Payment::query()->count());
    }

    public function test_cancellation_cannot_bypass_lifecycle_validation_and_audit(): void
    {
        $invoice = IssuedInvoice::factory()->paid()->create();

        try {
            // paid → cancelled je zakázaný přechod; přímý zápis se o mapu
            // přechodů vůbec nesmí otřít — guard ho odmítne dřív.
            $invoice->update(['status' => IssuedInvoiceStatus::Cancelled, 'cancelled_at' => now()]);
            $this->fail('Storno mimo lifecycle musí být odmítnuto.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $fresh = IssuedInvoice::query()->findOrFail($invoice->id);
        $this->assertSame(IssuedInvoiceStatus::Paid, $fresh->status);
        $this->assertNull($fresh->cancelled_at);
        $this->assertSame(0, AuditLog::query()->where('action', 'invoice.cancelled')->count());
    }

    public function test_invoice_number_cannot_be_planted_on_a_draft(): void
    {
        $draft = IssuedInvoice::factory()->draft()->create();

        $this->expectException(ImmutableInvoiceViolation::class);

        // Číslo přiděluje výhradně číselná řada při vystavení.
        $draft->update(['invoice_number' => 'PODVRH-0001']);
    }

    public function test_invoice_cannot_be_moved_to_another_organization(): void
    {
        $invoice = IssuedInvoice::factory()->draft()->create();

        $this->expectException(ImmutableInvoiceViolation::class);

        $invoice->update(['organization_id' => $invoice->organization_id + 1]);
    }

    public function test_former_public_lifecycle_methods_are_gone(): void
    {
        foreach (['applyIssued', 'applyPaymentState', 'applyCancelled', 'allowLifecycleTransition'] as $method) {
            $this->assertFalse(
                method_exists(IssuedInvoice::class, $method),
                "Veřejná metoda {$method}() nesmí existovat.",
            );
        }
    }

    /**
     * Statická pojistka: každá veřejná metoda deklarovaná přímo v modelu
     * musí být ve schváleném seznamu bezpečných metod (relace, dotazy,
     * odvozené hodnoty). Nová veřejná mutační metoda tenhle test shodí
     * a vynutí vědomé rozhodnutí v review.
     */
    public function test_issued_invoice_declares_no_unexpected_public_method(): void
    {
        $safe = [
            'persistedStatus',
            'contact', 'project', 'bankAccount', 'numberSeries', 'items', 'payments',
            'scopeOverdue', 'isOverdue',
            'totalMoney', 'paidAmountMoney', 'remainingMoney',
            'isEditable',
        ];

        $this->assertSame([], array_diff($this->declaredPublicMethods(IssuedInvoice::class), $safe));
    }

    public function test_payments_are_append_only(): void
    {
        $invoice = IssuedInvoice::factory()->issued()->create();
        $payment = Payment::factory()->create([
            'organization_id' => $invoice->organization_id,
            'payable_type' => IssuedInvoice::class,
            'payable_id' => $invoice->id,
            'amount_minor' => 1000,
        ]);

        try {
            $payment->update(['amount_minor' => 999999]);
            $this->fail('Platbu nesmí jít změnit.');
        } catch (ImmutablePaymentViolation) {
            // očekáváno
        }

        try {
            $payment->delete();
            $this->fail('Platbu nesmí jít smazat.');
        } catch (ImmutablePaymentViolation) {
            // očekáváno
        }

        $this->assertSame(1000, (int) Payment::query()->findOrFail($payment->id)->amount_minor);
    }

    /**
     * @return list<string>
     */
    private function declaredPublicMethods(string $class): array
    {
        $reflection = new ReflectionClass($class);

        $methods = array_values(array_map(
            fn (ReflectionMethod $method): string => $method->getName(),
            array_filter(
                $reflection->getMethods(ReflectionMethod::IS_PUBLIC),
                // Jen metody napsané přímo v souboru modelu — traity
                // (HasFactory, BelongsToOrganization) a framework se
                // hlídají jinde.
                fn (ReflectionMethod $method): bool => $method->getFileName() === $reflection->getFileName(),
            ),
        ));

        sort($methods);

        return $methods;
    }
}
