<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Pdf\InvoicePdfDataFactory;
use App\Domain\Pdf\InvoicePdfRenderer;
use App\Models\IssuedInvoice;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class InvoicePdfController extends Controller
{
    public function __construct(
        private readonly InvoicePdfDataFactory $factory,
        private readonly InvoicePdfRenderer $renderer,
    ) {
    }

    public function __invoke(IssuedInvoice $invoice): Response
    {
        $pdf = $this->renderer->render($this->factory->fromInvoice($invoice));

        $filename = $invoice->invoice_number
            ? 'faktura-'.Str::slug($invoice->invoice_number).'.pdf'
            : 'koncept-faktury-'.$invoice->id.'.pdf';

        return response($pdf, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
    }
}
