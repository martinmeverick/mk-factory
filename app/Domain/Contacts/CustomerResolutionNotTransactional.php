<?php

declare(strict_types=1);

namespace App\Domain\Contacts;

use DomainException;

/**
 * Email-only párování zákazníka bylo zavoláno uvnitř cizí transakce.
 *
 * Serializaci souběhu drží aplikační zámek (GET_LOCK), který je vázaný na
 * SESSION, ne na transakci. Uvnitř nadřazené transakce se zámek uvolní ve
 * chvíli, kdy resolver skončí — tedy PŘED commitem volajícího. Druhý
 * souběžný požadavek by v tu chvíli zámek získal, ale nově založený kontakt
 * by ještě neviděl, a založil by druhý. Fail closed je proto jediná
 * poctivá odpověď: kontrakt zní „zákazníka vyřeš PŘED transakcí“.
 *
 * Silnější klíče (external_id, IČO) tímhle omezené nejsou — kryje je
 * unikátní index, zámek nepotřebují a uvnitř transakce běžet mohou.
 */
class CustomerResolutionNotTransactional extends DomainException
{
    public static function make(): self
    {
        return new self(
            'Párování zákazníka podle e-mailu nesmí běžet uvnitř otevřené transakce: '
            .'aplikační zámek by se uvolnil dřív než commit a souběžný požadavek by '
            .'založil duplicitní kontakt. Vyřešte zákazníka před zahájením transakce '
            .'(nebo pošlete external_id).'
        );
    }
}
