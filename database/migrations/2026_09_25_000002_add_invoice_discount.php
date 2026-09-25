<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issued_invoices', function (Blueprint $table): void {
            $table->string('discount_type', 10)->default('none');
            $table->decimal('discount_value', 20, 2)->default(0);
            $table->bigInteger('discount_total_minor')->default(0);
        });
        Schema::table('issued_invoice_items', function (Blueprint $table): void {
            $table->bigInteger('line_discount_minor')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('issued_invoice_items', fn (Blueprint $table) => $table->dropColumn('line_discount_minor'));
        Schema::table('issued_invoices', fn (Blueprint $table) => $table->dropColumn(['discount_type', 'discount_value', 'discount_total_minor']));
    }
};
