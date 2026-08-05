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

    private const string DISK = 'local';

    /**
     * Zkopíruje aktuální logo organizace a vrátí relativní cestu snapshotu.
     * Vrací null, když organizace logo nemá.
     */
    public function capture(Organization $organization, IssuedInvoice $invoice): ?string
    {
        $source = $organization->logo_path;

        if ($source === null || ! Storage::disk(self::DISK)->exists($source)) {
            return null;
        }

        $contents = Storage::disk(self::DISK)->get($source);

        if ($contents === null) {
            return null;
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

        // Stejný obsah pro stejnou fakturu se nekopíruje podruhé.
        if (! Storage::disk(self::DISK)->exists($path)) {
            Storage::disk(self::DISK)->put($path, $contents);
        }

        return $path;
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
