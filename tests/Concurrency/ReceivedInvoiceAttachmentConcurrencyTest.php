<?php

declare(strict_types=1);

namespace Tests\Concurrency;

use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Domain\Tenancy\CurrentOrganization;
use App\Enums\ContactType;
use App\Enums\ReceivedInvoiceStatus;
use App\Models\Contact;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * CLOSURE REVIEW, nález 1: mazání JEDNÉ přílohy mohlo obejít finalitu
 * přijaté faktury.
 *
 * Původní cesta (`AttachmentController::destroy()` → `$attachment->delete()`)
 * kontrolovala stav rodiče guardem na modelu. Ten sice četl DATABÁZI, ale
 * BEZ zámku, takže mezi „přečteno" a „smazáno" se vešel cizí `markPaid()`.
 * Deterministická reprodukce nad MariaDB skončila:
 *
 *     phase=checked  result=deleted  final_status=paid  attachment_count=0
 *
 * Sekvenční stale guard tedy fungoval, skutečný souběh ne. Oprava přesouvá
 * operaci do `ReceivedInvoiceLifecycle::deleteAttachment()` pod zámek
 * rodiče — obě serializované varianty jsou dole ověřené zvlášť.
 *
 * Determinismus nedělá sleep: `WorkerBarrier` pustí workery do kritické
 * sekce současně a `ProcessHandshake` pak vynutí přesně to prokládání,
 * které chybu reprodukovalo. Čekání jsou vždy shora omezená — protistrana
 * může legitimně viset na zámku, který drží ten druhý.
 */
class ReceivedInvoiceAttachmentConcurrencyTest extends ConcurrencyTestCase
{
    /**
     * Jak dlouho smí worker uvnitř kritické sekce čekat na protistranu.
     * Po opravě čekání VYPRŠÍ (protistrana visí na zámku) — to je součást
     * očekávaného průběhu, ne selhání.
     */
    private const float PEER_TIMEOUT_SECONDS = 2.0;

    /**
     * Čekání na signál z kritické sekce protistrany; ta se k němu dostane
     * hned, jde tedy jen o pojistku proti zaseknutí.
     */
    private const float SIGNAL_TIMEOUT_SECONDS = 20.0;

    private Organization $organization;

    private ProcessHandshake $handshake;

    private ?string $storedPath = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->handshake = ProcessHandshake::create();
    }

    protected function tearDown(): void
    {
        $this->handshake->cleanup();

        if ($this->storedPath !== null) {
            Storage::disk('local')->delete($this->storedPath);
            $this->storedPath = null;
        }

        parent::tearDown();
    }

    /**
     * @return array{0: ReceivedInvoice, 1: ReceivedInvoiceAttachment}
     */
    private function seedInvoiceWithAttachment(): array
    {
        $this->organization = Organization::withoutGlobalScope('organization')->create([
            'name' => 'Souběh příloh s.r.o.',
            'country' => 'CZ',
        ]);

        $supplier = Contact::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'type' => ContactType::Supplier,
            'name' => 'Dodavatel '.uniqid(),
            'country' => 'CZ',
        ]);

        $invoice = ReceivedInvoice::withoutGlobalScope('organization')->create([
            'organization_id' => $this->organization->id,
            'contact_id' => $supplier->id,
            'supplier_invoice_number' => '2026001',
            'issue_date' => '2026-08-01',
            'received_date' => '2026-08-02',
            'due_date' => '2026-08-15',
            'currency' => 'CZK',
            'total_minor' => 60500,
            'status' => ReceivedInvoiceStatus::Received,
        ]);

        // Skutečný soubor na skutečném disku — pořadí „DB commit, pak
        // filesystem" se má ověřit, ne odsimulovat.
        $this->storedPath = 'attachments/org-'.$invoice->organization_id.'/'.Str::random(40).'.pdf';
        Storage::disk('local')->put($this->storedPath, 'PDF');

        $attachment = ReceivedInvoiceAttachment::withoutGlobalScope('organization')->create([
            'organization_id' => $invoice->organization_id,
            'received_invoice_id' => $invoice->id,
            'original_filename' => 'doklad.pdf',
            'stored_path' => $this->storedPath,
            'mime_type' => 'application/pdf',
            'size_bytes' => 3,
        ]);

        return [$invoice, $attachment];
    }

    /**
     * Uvnitř potomka: čerstvý tenant context a čerstvé instance.
     *
     * @return array{0: ReceivedInvoiceLifecycle, 1: ReceivedInvoice}
     */
    private function lifecycleFor(int $invoiceId): array
    {
        app(CurrentOrganization::class)->set(
            Organization::withoutGlobalScope('organization')->findOrFail($this->organization->id)
        );

        $invoice = ReceivedInvoice::withoutGlobalScope('organization')->findOrFail($invoiceId);

        return [app(ReceivedInvoiceLifecycle::class), $invoice];
    }

    private function statusOf(int $invoiceId): ?string
    {
        $status = DB::table('received_invoices')->where('id', $invoiceId)->value('status');

        return $status === null ? null : (string) $status;
    }

    private function attachmentExists(int $attachmentId): bool
    {
        return DB::table('received_invoice_attachments')->where('id', $attachmentId)->exists();
    }

    /**
     * Varianta A — `markPaid` získá zámek rodiče PRVNÍ.
     *
     * Mazání přílohy musí být odmítnuto podle skutečného (uhrazeného)
     * stavu a příloha i její soubor musí zůstat.
     *
     * Prokládání: `markPaid` signalizuje z místa UVNITŘ své transakce, kde
     * už zámek rodiče drží, a čeká, dokud protistrana svůj pokus nedokončí.
     * Po opravě protistrana visí na zámku a čekání vyprší — bez opravy se
     * mezitím stihne dostat ke svému DELETE nad ještě neuhrazeným řádkem.
     */
    public function test_mark_paid_that_wins_the_lock_rejects_the_concurrent_attachment_delete(): void
    {
        [$invoice, $attachment] = $this->seedInvoiceWithAttachment();
        $invoiceId = (int) $invoice->id;
        $attachmentId = (int) $attachment->id;
        $storedPath = (string) $attachment->stored_path;
        $handshake = $this->handshake;

        $errors = $this->runInParallel([
            // Worker 0 — markPaid (drží zámek rodiče).
            function (WorkerBarrier $barrier) use ($invoiceId, $handshake): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);

                // Platba vzniká uvnitř transakce markPaid nad ZAMČENÝM
                // řádkem — přesně tam, kde má protistrana narazit.
                Payment::created(function () use ($handshake): void {
                    $handshake->signal('mark-paid-locked');
                    $handshake->await('delete-finished', self::PEER_TIMEOUT_SECONDS);
                });

                $barrier();

                $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-05'));
            },
            // Worker 1 — mazání přílohy.
            function (WorkerBarrier $barrier) use ($invoiceId, $attachmentId, $handshake): void {
                [$lifecycle] = $this->lifecycleFor($invoiceId);

                $attachment = ReceivedInvoiceAttachment::withoutGlobalScope('organization')
                    ->findOrFail($attachmentId);

                $barrier();

                if (! $handshake->await('mark-paid-locked', self::SIGNAL_TIMEOUT_SECONDS)) {
                    throw new RuntimeException('Protistrana se nedostala do kritické sekce.');
                }

                try {
                    $lifecycle->deleteAttachment($attachment);
                } finally {
                    // I odmítnutí je dokončený pokus — bez tohoto signálu
                    // by protistrana zbytečně čekala do timeoutu.
                    $handshake->signal('delete-finished');
                }
            },
        ]);

        $this->assertSame('', $errors[0], 'markPaid musí projít: '.$errors[0]);
        $this->assertStringContainsString(
            'InvalidStateTransition',
            $errors[1],
            'Mazání přílohy po finalizaci rodiče musí být odmítnuto, bylo: '.($errors[1] === '' ? '(úspěch)' : $errors[1]),
        );

        $this->assertSame(ReceivedInvoiceStatus::Paid->value, $this->statusOf($invoiceId));
        $this->assertSame(
            1,
            Payment::withoutGlobalScope('organization')->where('payable_id', $invoiceId)->count(),
        );

        $this->assertTrue(
            $this->attachmentExists($attachmentId),
            'Příloha uhrazené faktury nesmí zmizet — to je přesně reprodukovaná chyba.',
        );
        $this->assertTrue(
            Storage::disk('local')->exists($storedPath),
            'Odmítnuté mazání nesmí sáhnout na soubor.',
        );
    }

    /**
     * Varianta B — mazání přílohy získá zámek rodiče PRVNÍ.
     *
     * Příloha smí zmizet a následný `markPaid` pracuje nad konzistentním
     * stavem. Zakázaný výsledek je opačný: příloha odstraněná až PO
     * finalizačním zámku.
     *
     * Protistrana je proto puštěna z místa mezi kontrolou stavu a DELETE
     * — tedy z okna, které původní implementace nechávala otevřené. Worker
     * pak přímo ověří, nad jakým stavem rodiče své mazání dokončuje.
     */
    public function test_attachment_delete_that_wins_the_lock_never_runs_after_finalization(): void
    {
        [$invoice, $attachment] = $this->seedInvoiceWithAttachment();
        $invoiceId = (int) $invoice->id;
        $attachmentId = (int) $attachment->id;
        $storedPath = (string) $attachment->stored_path;
        $handshake = $this->handshake;

        $errors = $this->runInParallel([
            // Worker 0 — mazání přílohy (má zámek rodiče držet).
            function (WorkerBarrier $barrier) use ($invoiceId, $attachmentId, $handshake): void {
                [$lifecycle] = $this->lifecycleFor($invoiceId);

                // Načtení modelu ho zaregistruje (booted) DŘÍV, než přidáme
                // vlastní posluchače — ten tedy poběží až ZA guardem modelu,
                // v okamžiku „zkontrolováno, ještě nesmazáno".
                $attachment = ReceivedInvoiceAttachment::withoutGlobalScope('organization')
                    ->findOrFail($attachmentId);

                ReceivedInvoiceAttachment::deleting(function () use ($invoiceId, $handshake): void {
                    $handshake->signal('delete-in-critical-section');
                    $handshake->await('mark-paid-committed', self::PEER_TIMEOUT_SECONDS);

                    $status = DB::table('received_invoices')->where('id', $invoiceId)->value('status');

                    if (in_array((string) $status, [
                        ReceivedInvoiceStatus::Paid->value,
                        ReceivedInvoiceStatus::Rejected->value,
                    ], true)) {
                        throw new RuntimeException(sprintf(
                            'RACE: příloha se maže, ačkoli je faktura už ve finálním stavu "%s".',
                            (string) $status,
                        ));
                    }
                });

                $barrier();

                $lifecycle->deleteAttachment($attachment);
            },
            // Worker 1 — markPaid.
            function (WorkerBarrier $barrier) use ($invoiceId, $handshake): void {
                [$lifecycle, $fresh] = $this->lifecycleFor($invoiceId);

                $barrier();

                if (! $handshake->await('delete-in-critical-section', self::SIGNAL_TIMEOUT_SECONDS)) {
                    throw new RuntimeException('Protistrana se nedostala do kritické sekce.');
                }

                try {
                    $lifecycle->markPaid($fresh, CarbonImmutable::parse('2026-08-05'));
                } finally {
                    $handshake->signal('mark-paid-committed');
                }
            },
        ]);

        $this->assertSame(
            '',
            $errors[0],
            'Mazání přílohy nesmí dokončit nad finalizovanou fakturou: '.$errors[0],
        );
        $this->assertSame('', $errors[1], 'markPaid musí projít: '.$errors[1]);

        $this->assertSame(ReceivedInvoiceStatus::Paid->value, $this->statusOf($invoiceId));
        $this->assertSame(
            1,
            Payment::withoutGlobalScope('organization')->where('payable_id', $invoiceId)->count(),
        );

        $this->assertFalse(
            $this->attachmentExists($attachmentId),
            'Mazání, které zámek získalo první, musí přílohu odstranit.',
        );
        $this->assertFalse(
            Storage::disk('local')->exists($storedPath),
            'Soubor se maže až PO commitu, ale musí zmizet.',
        );
    }
}
