<?php

declare(strict_types=1);

namespace Tests\Feature\Domain;

use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Enums\IssuedInvoiceStatus;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\IssuedInvoice;
use App\Models\IssuedInvoiceItem;
use App\Models\Organization;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * RE-REVIEW, nález 3: guard potomků načítal stav rodiče podle AKTUÁLNÍ
 * (tedy už změněné) hodnoty parent ID. Šlo proto:
 *   - položku vystavené faktury přesunout na koncept a tím ji z dokladu
 *     odstranit (guard se ptal na koncept a změnu povolil),
 *   - přílohu uhrazené faktury přesunout na nefinální přijatou fakturu,
 *   - potomka přesunout do cizí organizace (organization_id neměl guard
 *     vůbec žádný).
 *
 * Vlastnická vazba je teď po vytvoření NEMĚNNÁ a guard finality vychází
 * z PŮVODNÍHO rodiče.
 */
class InvoiceChildOwnershipTest extends TestCase
{
    use RefreshDatabase;

    private function issuedInvoiceWithItem(): array
    {
        $invoice = IssuedInvoice::factory()->draft()->create();
        $item = IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
            'description' => 'Původní popis',
        ]);

        // Stav se podvrhne přímo v DB — Eloquent cesta stavy nemění.
        DB::table('issued_invoices')->where('id', $invoice->id)
            ->update(['status' => IssuedInvoiceStatus::Issued->value]);

        return [$invoice, $item];
    }

    public function test_item_of_an_issued_invoice_cannot_be_moved_to_a_draft(): void
    {
        [$issued, $item] = $this->issuedInvoiceWithItem();

        $draft = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $issued->organization_id,
        ]);

        try {
            $item->update(['issued_invoice_id' => $draft->id]);
            $this->fail('Položku vystavené faktury nesmí jít přesunout na koncept.');
        } catch (ImmutableInvoiceViolation $e) {
            $this->assertStringContainsString('issued_invoice_id', $e->getMessage());
        }

        $this->assertSame(
            $issued->id,
            IssuedInvoiceItem::query()->findOrFail($item->id)->issued_invoice_id,
            'Parent ID musí zůstat původní.',
        );
        $this->assertSame(1, IssuedInvoiceItem::query()->where('issued_invoice_id', $issued->id)->count());
        $this->assertSame(0, IssuedInvoiceItem::query()->where('issued_invoice_id', $draft->id)->count());
    }

    public function test_item_cannot_be_moved_to_another_issued_invoice(): void
    {
        [$issued, $item] = $this->issuedInvoiceWithItem();

        $otherIssued = IssuedInvoice::factory()->issued()->create([
            'organization_id' => $issued->organization_id,
        ]);

        $this->expectException(ImmutableInvoiceViolation::class);

        $item->update(['issued_invoice_id' => $otherIssued->id]);
    }

    public function test_item_of_a_draft_cannot_be_moved_either(): void
    {
        $draft = IssuedInvoice::factory()->draft()->create();
        $item = IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $draft->id,
            'organization_id' => $draft->organization_id,
        ]);

        $otherDraft = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $draft->organization_id,
        ]);

        // Reparenting není podporovaná operace v žádném stavu — položky se
        // přepisují smazáním a založením (IssuedInvoiceLifecycle::updateDraft).
        $this->expectException(ImmutableInvoiceViolation::class);

        $item->update(['issued_invoice_id' => $otherDraft->id]);
    }

    public function test_item_cannot_be_moved_to_another_organization(): void
    {
        $draft = IssuedInvoice::factory()->draft()->create();
        $item = IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $draft->id,
            'organization_id' => $draft->organization_id,
        ]);

        $foreign = Organization::factory()->create();

        try {
            $item->update(['organization_id' => $foreign->id]);
            $this->fail('Položku nesmí jít přesunout do cizí organizace.');
        } catch (ImmutableInvoiceViolation $e) {
            $this->assertStringContainsString('organizace', $e->getMessage());
        }

        $this->assertSame(
            (int) $draft->organization_id,
            (int) IssuedInvoiceItem::query()->withoutGlobalScope('organization')->findOrFail($item->id)->organization_id,
        );
    }

    /**
     * Podvržené parent ID jen v paměti: delete() maže podle primárního
     * klíče, takže by se řádek smazal i tak — guard proto musí vycházet
     * z PŮVODNÍHO rodiče, ne z nové hodnoty.
     */
    public function test_deleting_with_a_tampered_parent_id_still_checks_the_original_parent(): void
    {
        [$issued, $item] = $this->issuedInvoiceWithItem();

        $draft = IssuedInvoice::factory()->draft()->create([
            'organization_id' => $issued->organization_id,
        ]);

        $item->issued_invoice_id = $draft->id;

        try {
            $item->delete();
            $this->fail('Položku vystavené faktury nesmí jít smazat podvržením parent ID.');
        } catch (ImmutableInvoiceViolation) {
            // očekáváno
        }

        $this->assertDatabaseHas('issued_invoice_items', ['id' => $item->id]);
    }

    public function test_legitimate_item_edit_on_a_draft_still_works(): void
    {
        $draft = IssuedInvoice::factory()->draft()->create();
        $item = IssuedInvoiceItem::factory()->create([
            'issued_invoice_id' => $draft->id,
            'organization_id' => $draft->organization_id,
        ]);

        $item->update(['description' => 'Upraveno', 'unit_price_minor' => 4200]);

        $fresh = IssuedInvoiceItem::query()->findOrFail($item->id);
        $this->assertSame('Upraveno', $fresh->description);
        $this->assertSame(4200, (int) $fresh->unit_price_minor);
    }

    // ---------- přílohy přijatých faktur ----------

    private function paidInvoiceWithAttachment(): array
    {
        $invoice = ReceivedInvoice::factory()->create();
        $attachment = ReceivedInvoiceAttachment::factory()->create([
            'received_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);

        DB::table('received_invoices')->where('id', $invoice->id)
            ->update(['status' => ReceivedInvoiceStatus::Paid->value]);

        return [$invoice, $attachment];
    }

    public function test_attachment_of_a_paid_invoice_cannot_be_moved_to_a_received_one(): void
    {
        [$paid, $attachment] = $this->paidInvoiceWithAttachment();

        $open = ReceivedInvoice::factory()->create(['organization_id' => $paid->organization_id]);

        try {
            $attachment->update(['received_invoice_id' => $open->id]);
            $this->fail('Přílohu uhrazené faktury nesmí jít přesunout.');
        } catch (ImmutableInvoiceViolation $e) {
            $this->assertStringContainsString('received_invoice_id', $e->getMessage());
        }

        $this->assertSame(
            $paid->id,
            ReceivedInvoiceAttachment::query()->findOrFail($attachment->id)->received_invoice_id,
        );
    }

    public function test_attachment_of_a_rejected_invoice_cannot_be_moved(): void
    {
        $rejected = ReceivedInvoice::factory()->create();
        $attachment = ReceivedInvoiceAttachment::factory()->create([
            'received_invoice_id' => $rejected->id,
            'organization_id' => $rejected->organization_id,
        ]);

        DB::table('received_invoices')->where('id', $rejected->id)
            ->update(['status' => ReceivedInvoiceStatus::Rejected->value]);

        $open = ReceivedInvoice::factory()->create(['organization_id' => $rejected->organization_id]);

        $this->expectException(ImmutableInvoiceViolation::class);

        $attachment->update(['received_invoice_id' => $open->id]);
    }

    public function test_attachment_cannot_be_moved_to_another_organization(): void
    {
        $invoice = ReceivedInvoice::factory()->create();
        $attachment = ReceivedInvoiceAttachment::factory()->create([
            'received_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);

        $this->expectException(ImmutableInvoiceViolation::class);

        $attachment->update(['organization_id' => Organization::factory()->create()->id]);
    }

    public function test_deleting_an_attachment_with_a_tampered_parent_id_checks_the_original_parent(): void
    {
        [$paid, $attachment] = $this->paidInvoiceWithAttachment();

        $open = ReceivedInvoice::factory()->create(['organization_id' => $paid->organization_id]);
        $attachment->received_invoice_id = $open->id;

        $this->expectException(ImmutableInvoiceViolation::class);

        $attachment->delete();
    }

    public function test_legitimate_attachment_edit_on_a_non_final_invoice_still_works(): void
    {
        $invoice = ReceivedInvoice::factory()->create();
        $attachment = ReceivedInvoiceAttachment::factory()->create([
            'received_invoice_id' => $invoice->id,
            'organization_id' => $invoice->organization_id,
        ]);

        $attachment->update(['original_filename' => 'prejmenovano.pdf']);

        $this->assertSame(
            'prejmenovano.pdf',
            ReceivedInvoiceAttachment::query()->findOrFail($attachment->id)->original_filename,
        );
    }
}
