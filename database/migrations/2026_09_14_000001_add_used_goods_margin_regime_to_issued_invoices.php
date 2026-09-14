<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Zvláštní režim - použité zboží (§ 90 ZDPH) — explicitní režim DPH na
 * úrovni faktury + interní evidence přirážky.
 *
 * Výchozí hodnota 'standard' = dosavadní chování (plátce/neplátce dle
 * položek). Historické vystavené faktury se tím NEreinterpretují: jejich
 * položky, součty ani snapshoty se nemění a čtenáři (PDF, detail) je dál
 * vyhodnocují stejně jako dosud. Nové sloupce jsou po vystavení neměnné
 * (viz IssuedInvoice::PROTECTED_ATTRIBUTES).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_invoices', function (Blueprint $table) {
            $table->string('vat_regime', 32)->default('standard');
            // Interní sazba DPH z přirážky (jen used_goods_margin), např. 21.00.
            $table->decimal('margin_vat_rate', 5, 2)->nullable();
            // Interní evidence (netiskne se): součet pořizovacích cen, kladná
            // přirážka, DPH z přirážky a základ daně z přirážky.
            $table->bigInteger('margin_acquisition_total_minor')->default(0);
            $table->bigInteger('margin_gross_minor')->default(0);
            $table->bigInteger('margin_vat_minor')->default(0);
            $table->bigInteger('margin_base_minor')->default(0);
        });

        Schema::table('issued_invoice_items', function (Blueprint $table) {
            // Interní pořizovací cena za MJ (jen used_goods_margin; null u běžného režimu).
            $table->bigInteger('acquisition_unit_price_minor')->nullable();
            $table->bigInteger('line_acquisition_minor')->default(0);
            $table->bigInteger('line_margin_gross_minor')->default(0);
            $table->bigInteger('line_margin_vat_minor')->default(0);
            $table->bigInteger('line_margin_base_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('issued_invoice_items', function (Blueprint $table) {
            $table->dropColumn([
                'acquisition_unit_price_minor',
                'line_acquisition_minor',
                'line_margin_gross_minor',
                'line_margin_vat_minor',
                'line_margin_base_minor',
            ]);
        });

        Schema::table('issued_invoices', function (Blueprint $table) {
            $table->dropColumn([
                'vat_regime',
                'margin_vat_rate',
                'margin_acquisition_total_minor',
                'margin_gross_minor',
                'margin_vat_minor',
                'margin_base_minor',
            ]);
        });
    }
};
