<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\IssuedInvoice;
use DomainException;

class ImmutableInvoiceViolation extends DomainException
{
    public static function forAttribute(IssuedInvoice $invoice, string $attribute): self
    {
        return new self(sprintf(
            'Vystavenou fakturu %s nelze měnit: atribut "%s" je po vystavení neměnný.',
            $invoice->invoice_number ?? '#'.$invoice->getKey(),
            $attribute,
        ));
    }

    public static function forDeletion(IssuedInvoice $invoice): self
    {
        return new self(sprintf(
            'Fakturu %s nelze smazat: smazat lze pouze koncept.',
            $invoice->invoice_number ?? '#'.$invoice->getKey(),
        ));
    }

    public static function forItems(IssuedInvoice $invoice): self
    {
        return new self(sprintf(
            'Položky faktury %s nelze měnit: faktura již není koncept.',
            $invoice->invoice_number ?? '#'.$invoice->getKey(),
        ));
    }
}
