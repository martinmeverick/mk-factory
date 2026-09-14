<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * IČO musí být v rámci organizace unikátní — na tom stojí find-or-create
 * dodavatele podle IČO (viz App\Domain\Contacts\SupplierResolver).
 *
 * NULL se v unikátním indexu opakovat smí, takže kontakty bez IČO
 * (fyzické osoby) nejsou omezené. Prázdné řetězce se proto normalizují
 * na NULL, jinak by kolidovaly mezi sebou.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('contacts')->where('ico', '')->update(['ico' => null]);

        Schema::table('contacts', function (Blueprint $table) {
            $table->unique(['organization_id', 'ico']);
        });
    }

    public function down(): void
    {
        Schema::table('contacts', function (Blueprint $table) {
            $table->dropUnique(['organization_id', 'ico']);
        });
    }
};
