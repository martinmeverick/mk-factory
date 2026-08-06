<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\IssuedInvoice;
use App\Models\ReceivedInvoice;
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

    /**
     * Lifecycle pole (stav, číslo, úhrady, časy přechodů) zapisuje výhradně
     * lifecycle služba — veřejná zápisová cesta je nesmí měnit v žádném stavu.
     */
    public static function forLifecycleAttribute(IssuedInvoice $invoice, string $attribute): self
    {
        return new self(sprintf(
            'Atribut "%s" faktury %s nelze zapsat přímo — mění ho výhradně IssuedInvoiceLifecycle.',
            $attribute,
            $invoice->invoice_number ?? '#'.$invoice->getKey(),
        ));
    }

    public static function forTenantChange(IssuedInvoice|ReceivedInvoice $invoice): self
    {
        return new self(sprintf(
            'Fakturu #%s nelze přesunout do jiné organizace.',
            $invoice->getKey() ?? '?',
        ));
    }

    public static function forReceivedLifecycleAttribute(ReceivedInvoice $invoice, string $attribute): self
    {
        return new self(sprintf(
            'Atribut "%s" přijaté faktury #%s nelze zapsat přímo — mění ho výhradně ReceivedInvoiceLifecycle.',
            $attribute,
            $invoice->getKey() ?? '?',
        ));
    }

    public static function forFinalReceivedInvoice(ReceivedInvoice $invoice): self
    {
        return new self(sprintf(
            'Přijatou fakturu #%s už nelze měnit: je uhrazená nebo zamítnutá.',
            $invoice->getKey() ?? '?',
        ));
    }

    public static function forReceivedDeletion(ReceivedInvoice $invoice): self
    {
        return new self(sprintf(
            'Uhrazenou přijatou fakturu #%s nelze smazat.',
            $invoice->getKey() ?? '?',
        ));
    }

    public static function forPaidReceivedAttachments(int $invoiceId): self
    {
        return new self(sprintf(
            'Přílohy přijaté faktury #%d nelze měnit: faktura je uhrazená.',
            $invoiceId,
        ));
    }
}
