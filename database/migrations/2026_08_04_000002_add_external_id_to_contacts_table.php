<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Externí identifikátor kontaktu pro napojení na jiné systémy (U Jabka, MEX,
 * Cashflow). Integrace se na zákazníka odkazuje TÍMTO klíčem, nikdy názvem
 * ani e-mailem.
 *
 * Zásadní pro B2C: odběratelem bývá fyzická osoba bez IČO, takže IČO nemůže
 * sloužit jako párovací klíč. external_id je proto primární klíč integrace
 * a zůstává nezávislý na tom, zda zákazník IČO má.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('note');
            $table->unique(['organization_id', 'external_id']);
            $table->index(['organization_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'external_id']);
            $table->dropIndex(['organization_id', 'email']);
            $table->dropColumn('external_id');
        });
    }
};
