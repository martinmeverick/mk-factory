<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('issued_invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('description');
            $table->decimal('quantity', 12, 3);
            $table->string('unit', 20)->default('ks');
            $table->bigInteger('unit_price_minor');
            $table->decimal('vat_rate', 5, 2)->nullable();
            $table->bigInteger('line_subtotal_minor')->default(0);
            $table->bigInteger('line_vat_minor')->default(0);
            $table->bigInteger('line_total_minor')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_invoice_items');
    }
};
