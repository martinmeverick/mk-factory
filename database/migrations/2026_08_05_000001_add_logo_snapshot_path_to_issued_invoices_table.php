<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Logo zmrazené k okamžiku vystavení faktury.
 *
 * Historický doklad se nesmí zpětně změnit tím, že organizace nahraje nebo
 * smaže logo. Ukládá se relativní cesta ke KOPII souboru na disku `local`
 * (viz App\Domain\Pdf\InvoiceLogoSnapshotStore), ne odkaz na aktuální
 * logo organizace.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_invoices', function (Blueprint $table) {
            $table->string('logo_snapshot_path')->nullable()->after('footer_text');
        });
    }

    public function down(): void
    {
        Schema::table('issued_invoices', function (Blueprint $table) {
            $table->dropColumn('logo_snapshot_path');
        });
    }
};
