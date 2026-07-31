<?php

declare(strict_types=1);

namespace App\Domain\Invoicing;

use App\Models\InvoiceNumberSeries;
use Illuminate\Support\Facades\DB;

/**
 * Přidělování čísel z číselné řady (viz INVOICE_LIFECYCLE.md).
 *
 * Souběh řeší zámek řádku řady (SELECT … FOR UPDATE); unikátní index
 * issued_invoices(organization_id, invoice_number) je pojistka.
 */
final class InvoiceNumberGenerator
{
    public function nextNumber(InvoiceNumberSeries $series): string
    {
        return DB::transaction(function () use ($series): string {
            /** @var InvoiceNumberSeries $locked */
            $locked = InvoiceNumberSeries::query()
                ->withoutGlobalScope('organization')
                ->whereKey($series->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $number = $this->format($locked);

            $locked->next_number = $locked->next_number + 1;
            $locked->save();

            // Promítne inkrement i do instance volajícího.
            $series->forceFill(['next_number' => $locked->next_number])->syncOriginal();

            return $number;
        });
    }

    /**
     * Tokeny: {PREFIX}, {YEAR} (4 číslice), {YY} (2 číslice),
     * {NUMBER:n} (pořadové číslo doplněné nulami na n míst).
     */
    private function format(InvoiceNumberSeries $series): string
    {
        $number = strtr($series->number_format, [
            '{PREFIX}' => $series->prefix,
            '{YEAR}' => sprintf('%04d', $series->year),
            '{YY}' => sprintf('%02d', $series->year % 100),
        ]);

        return (string) preg_replace_callback(
            '/\{NUMBER:(\d+)\}/',
            fn (array $matches): string => str_pad(
                (string) $series->next_number,
                (int) $matches[1],
                '0',
                STR_PAD_LEFT,
            ),
            $number,
        );
    }
}
