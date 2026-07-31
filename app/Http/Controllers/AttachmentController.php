<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ReceivedInvoice;
use App\Models\ReceivedInvoiceAttachment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentController extends Controller
{
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

        $this->attach($received, $request->file('attachment'));

        return redirect()->route('received.show', $received)->with('status', 'Příloha byla nahrána.');
    }

    /**
     * Uloží soubor pod hash názvem na privátní disk a založí záznam.
     */
    public function attach(ReceivedInvoice $received, UploadedFile $file): ReceivedInvoiceAttachment
    {
        $path = $file->store('attachments/org-'.$received->organization_id, 'local');

        return $received->attachments()->create([
            'organization_id' => $received->organization_id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $path,
            'mime_type' => $file->getMimeType() ?? 'application/octet-stream',
            'size_bytes' => $file->getSize() ?: 0,
        ]);
    }

    public function download(ReceivedInvoiceAttachment $attachment): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($attachment->stored_path), 404);

        return Storage::disk('local')->download($attachment->stored_path, $attachment->original_filename);
    }

    public function destroy(ReceivedInvoiceAttachment $attachment): RedirectResponse
    {
        $received = $attachment->receivedInvoice;

        Storage::disk('local')->delete($attachment->stored_path);
        $attachment->delete();

        return redirect()->route('received.show', $received)->with('status', 'Příloha byla smazána.');
    }
}
