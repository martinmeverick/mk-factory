<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use DomainException;

/**
 * Aplikační zámek pro email-only párování se nepodařilo získat v limitu.
 *
 * Doménová chyba, ne obecná RuntimeException: volající (typicky integrace)
 * ji má odlišit od programátorské chyby a požadavek zopakovat.
 */
class CustomerLockUnavailable extends DomainException
{
    public static function afterSeconds(int $seconds): self
    {
        return new self(sprintf(
            'Zámek pro párování zákazníka podle e-mailu se nepodařilo získat do %d s. '
            .'Požadavek nebyl proveden — zkuste ho zopakovat.',
            $seconds,
        ));
    }
}
