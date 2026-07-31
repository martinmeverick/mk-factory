<?php

declare(strict_types=1);

namespace App\Domain\Pdf;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * Render faktury do PDF přes dompdf (view resources/views/pdf/invoice.blade.php).
 *
 * - Font DejaVu Sans (bundlovaný v dompdf, plná čeština) — žádné doinstalace.
 * - isRemoteEnabled zůstává false: logo i QR jdou výhradně přes data URI,
 *   šablona nesmí odkazovat na vzdálené zdroje (bezpečnost / SSRF).
 * - PDF se nekešuje, generuje se on-demand (viz ARCHITECTURE.md).
 */
final class InvoicePdfRenderer
{
    public function render(InvoicePdfData $data): string
    {
        return Pdf::loadView('pdf.invoice', ['data' => $data])
            ->setPaper('a4')
            ->setOptions([
                'defaultFont' => 'DejaVu Sans',
                'isRemoteEnabled' => false,
                'isPhpEnabled' => false,
                'isHtml5ParserEnabled' => true,
            ])
            ->output();
    }
}
