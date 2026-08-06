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

    /**
     * Organizace logo NAKONFIGUROVANÉ má, ale soubor chybí. Vystavení se
     * musí zastavit — jinak by doklad tiše vznikl bez loga a spotřeboval
     * číslo řady. (Organizace bez loga je jiný, legitimní případ.)
     */
    public static function forMissingSource(string $sourcePath): self
    {
        return new self(sprintf(
            'Organizace má nastavené logo "%s", ale soubor na disku není. '
            .'Nahrajte logo znovu (nebo ho v nastavení odeberte) a fakturu vystavte potom.',
            $sourcePath,
        ));
    }

    public static function forUnsafeSource(string $sourcePath, string $expectedPrefix): self
    {
        return new self(sprintf(
            'Cesta loga "%s" není použitelná — očekává se relativní cesta začínající "%s".',
            $sourcePath,
            $expectedPrefix,
        ));
    }
}
