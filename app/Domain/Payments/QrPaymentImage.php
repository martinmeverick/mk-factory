<?php

declare(strict_types=1);

namespace App\Domain\Payments;

use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

/**
 * Vykreslení QR Platby do PNG jako data URI (endroid/qr-code 6.x, GD).
 * Data URI se vkládá přímo do PDF šablony i do detailu faktury —
 * bez externích požadavků (isRemoteEnabled zůstává vypnuté).
 */
final class QrPaymentImage
{
    public static function pngDataUri(SpdPayload $payload, int $size = 300): string
    {
        $builder = new Builder(
            writer: new PngWriter(),
            data: $payload->toString(),
            encoding: new Encoding('UTF-8'),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: $size,
            margin: max(4, intdiv($size, 30)),
            roundBlockSizeMode: RoundBlockSizeMode::Margin,
        );

        return $builder->build()->getDataUri();
    }
}
