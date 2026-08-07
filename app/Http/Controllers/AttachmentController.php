<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Invoicing\AttachmentNotFound;
use App\Domain\Invoicing\AttachmentStorageFailed;
use App\Domain\Invoicing\ImmutableInvoiceViolation;
use App\Domain\Invoicing\InvalidStateTransition;
use App\Domain\Invoicing\InvoiceNotFound;
use App\Domain\Invoicing\ReceivedInvoiceLifecycle;
use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
    public function __construct(
        private readonly ReceivedInvoiceLifecycle $lifecycle,
    ) {}

    /**
     * O tom, zda příloha smí vzniknout, rozhoduje až zamčený řádek
     * v lifecycle vrstvě — controller stav neposuzuje podle dřív načtené
     * instance a soubor ukládá až lifecycle, po kontrole stavu.
     */
    public function store(Request $request, ReceivedInvoice $received): RedirectResponse
    {
        $request->validate(
            ['attachment' => ['required', 'file', 'mimes:pdf,jpg,jpeg,png', 'max:10240']],
            [
                'attachment.mimes' => 'Příloha musí být PDF nebo obrázek (JPG/PNG).',
                'attachment.max' => 'Příloha může mít maximálně 10 MB.',
            ],
            ['attachment' => 'příloha'],
        );

        try {
            $this->lifecycle->attach($received, $request->file('attachment'));
        } catch (InvalidStateTransition|InvoiceNotFound|AttachmentStorageFailed $e) {
            return redirect()->route('received.show', $received)->with('error', $e->getMessage());
        }

        return redirect()->route('received.show', $received)->with('status', 'Příloha byla nahrána.');
    }

    public function download(ReceivedInvoiceAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
    }

    /**
     * O tom, zda příloha smí zmizet, rozhoduje ZAMČENÝ řádek rodičovské
     * faktury v lifecycle vrstvě. Controller stav dřív načtené instance
     * neposuzuje a soubor nemaže sám — jen zavolá doménovou operaci
     * a případnou doménovou chybu přeloží na kontrolovaný redirect.
     */
    public function destroy(ReceivedInvoiceAttachment $attachment): RedirectResponse
    {
        // Jen cíl redirectu; žádné rozhodnutí se z toho neodvozuje.
        $invoiceId = $attachment->received_invoice_id;

        try {
            $this->lifecycle->deleteAttachment($attachment);
        } catch (InvalidStateTransition|InvoiceNotFound|AttachmentNotFound|ImmutableInvoiceViolation $e) {
            return redirect()->route('received.show', $invoiceId)->with('error', $e->getMessage());
        }

        return redirect()->route('received.show', $invoiceId)->with('status', 'Příloha byla smazána.');
    }
}
