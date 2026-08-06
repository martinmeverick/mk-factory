<?php

declare(strict_types=1);

namespace App\Domain\Pdf;

use DomainException;
use Throwable;

/**
 * Snapshot loga se nepodařilo vytvořit nebo ověřit. Vystavení faktury se
 * v takovém případě MUSÍ zastavit — vystavený doklad nikdy nesmí odkazovat
 * na soubor, který neexistuje.
 */
class LogoSnapshotFailed extends DomainException
{
    public static function forSource(string $sourcePath, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Logo organizace "%s" se nepodařilo přečíst pro snapshot dokladu.', $sourcePath),
            0,
            $previous,
        );
    }

    public static function forTarget(string $targetPath, ?Throwable $previous = null): self
    {
        return new self(
            sprintf('Snapshot loga "%s" se nepodařilo zapsat — fakturu nelze vystavit.', $targetPath),
            0,
            $previous,
        );
    }
}
