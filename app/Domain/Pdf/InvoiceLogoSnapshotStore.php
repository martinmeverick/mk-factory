<?php

declare(strict_types=1);

namespace App\Domain\Pdf;

use App\Models\IssuedInvoice;
use App\Models\Organization;
use Illuminate\Support\Facades\Storage;

/**
 * Zmrazení loga organizace k okamžiku vystavení faktury.
 *
 * Ukládá se KOPIE souboru, ne odkaz na aktuální logo organizace — to může
 * být později přepsáno nebo smazáno a historický doklad by se tím zpětně
 * změnil. Cesta je relativní k disku `local` (žádná absolutní cesta závislá
 * na konkrétním počítači) a obsahuje organization_id, takže stažení lze
 * ověřit proti tenantu.
 */
final class InvoiceLogoSnapshotStore
{
    private const string DIRECTORY = 'invoice-logos';

    /**
     * Adresář, kam ukládá logo organizace nahrávací cesta v nastavení.
     */
    private const string LOGO_DIRECTORY = 'logos';

    private const string DISK = 'local';

    /**
     * Zkopíruje aktuální logo organizace a vrátí relativní cestu snapshotu.
     * Vrací null, jen když organizace logo NEMÁ. Má-li ho a kopie se
     * nepodaří vytvořit či ověřit, letí LogoSnapshotFailed — vystavený
     * doklad nikdy nesmí odkazovat na soubor, který neexistuje, a nesmí
     * ani tiše vzniknout bez povinného snapshotu.
     *
     * @throws LogoSnapshotFailed
     */
    public function capture(Organization $organization, IssuedInvoice $invoice): ?string
    {
        $source = $organization->logo_path;

        // Organizace logo NENÍ nakonfigurované — legitimní stav, faktura se
        // vystaví bez loga.
        if ($source === null || trim($source) === '') {
            return null;
        }

        // Od téhle chvíle je logo POVINNÉ: organizace ho nakonfigurovala,
        // takže doklad bez snapshotu by tiše ztratil část vzhledu a přitom
        // spotřeboval číslo řady. Každá další chyba proto vystavení zastaví.
        $this->assertUsableSourcePath($source, $organization);

        if (! Storage::disk(self::DISK)->exists($source)) {
            throw LogoSnapshotFailed::forMissingSource($source);
        }

        try {
            $contents = Storage::disk(self::DISK)->get($source);
        } catch (\Throwable $e) {
            throw LogoSnapshotFailed::forSource($source, $e);
        }

        if ($contents === null || $contents === '') {
            // Zdroj podle exists() existuje, ale přečíst nejde — doklad se
            // bez povinného snapshotu vystavit nesmí.
            throw LogoSnapshotFailed::forSource($source);
        }

        $extension = pathinfo($source, PATHINFO_EXTENSION) ?: 'png';
        $fingerprint = substr(hash('sha256', $contents), 0, 16);

        $path = sprintf(
            '%s/org-%d/invoice-%d-%s.%s',
            self::DIRECTORY,
            $organization->getKey(),
            $invoice->getKey(),
            $fingerprint,
            $extension,
        );

        // Stejný obsah pro stejnou fakturu se nekopíruje podruhé. Cesta je
        // unikátní pro (organizaci, fakturu, obsah), takže existující soubor
        // může být jen pozůstatek dřívějšího neúspěšného pokusu o vystavení
        // téže faktury — obsah je totožný.
        if (! Storage::disk(self::DISK)->exists($path)) {
            try {
                $written = Storage::disk(self::DISK)->put($path, $contents);
            } catch (\Throwable $e) {
                throw LogoSnapshotFailed::forTarget($path, $e);
            }

            // put() vrací bool — false znamená neúspěšný zápis a dřív se
            // tiše ignorovalo. Existence se ověřuje ještě jednou, aby
            // uložená cesta zaručeně vedla na skutečný soubor.
            if ($written !== true || ! Storage::disk(self::DISK)->exists($path)) {
                throw LogoSnapshotFailed::forTarget($path);
            }
        }

        return $path;
    }

    /**
     * Kompenzace neúspěšného vystavení: filesystem nemá společný commit
     * s databází, takže soubor vytvořený pokusem, který skončil rollbackem,
     * se maže dodatečně. Best-effort — po pádu procesu může osiřelý soubor
     * zůstat (bez odkazu z DB; další pokus ho přepíše stejným obsahem).
     */
    public function discard(string $path): void
    {
        try {
            Storage::disk(self::DISK)->delete($path);
        } catch (\Throwable) {
            // Neúspěšný úklid nesmí zamaskovat původní chybu vystavení.
        }
    }

    /**
     * Zdrojová cesta loga musí být relativní, bez traversalu a uvnitř
     * adresáře vlastní organizace — tam ji ukládá nahrávání loga
     * (OrganizationSettingsController). Cokoli jiného je poškozená nebo
     * podvržená konfigurace a vystavení musí zastavit, ne zkopírovat
     * neznámý soubor.
     */
    private function assertUsableSourcePath(string $source, Organization $organization): void
    {
        $expectedPrefix = sprintf('%s/org-%d/', self::LOGO_DIRECTORY, $organization->getKey());

        $invalid = str_contains($source, "\0")
            || str_contains($source, '..')
            || str_starts_with($source, '/')
            || preg_match('#^[a-zA-Z]:[\\\\/]#', $source) === 1
            || ! str_starts_with($source, $expectedPrefix);

        if ($invalid) {
            throw LogoSnapshotFailed::forUnsafeSource($source, $expectedPrefix);
        }
    }

    /**
     * Snapshot náleží organizaci, jejíž id je v cestě — kontrola tenant
     * izolace při čtení historického dokladu.
     */
    public function belongsToOrganization(string $path, int $organizationId): bool
    {
        return str_starts_with($path, sprintf('%s/org-%d/', self::DIRECTORY, $organizationId));
    }

    public function exists(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }
}
