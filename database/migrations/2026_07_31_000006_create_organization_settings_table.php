<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('organization_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('vat_payer')->default(false);
            $table->unsignedSmallInteger('default_due_days')->default(14);
            $table->foreignId('default_bank_account_id')->nullable()
                ->constrained('bank_accounts')->nullOnDelete();
            $table->foreignId('default_number_series_id')->nullable()
                ->constrained('invoice_number_series')->nullOnDelete();
            $table->text('invoice_footer_text')->nullable();
            $table->text('invoice_default_note')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('organization_settings');
    }
};
