<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use DomainException;

/**
 * Soubor přílohy se nepodařilo uložit nebo ověřit. Doklad nesmí získat
 * záznam přílohy, jejíž soubor na disku není — evidence by pak lhala.
 */
class AttachmentStorageFailed extends DomainException
{
    public static function forUpload(string $originalFilename): self
    {
        return new self(sprintf(
            'Soubor přílohy "%s" se nepodařilo uložit — příloha nebyla zaevidována.',
            $originalFilename,
        ));
    }
}
