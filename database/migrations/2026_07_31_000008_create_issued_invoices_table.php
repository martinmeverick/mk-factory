<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('issued_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('number_series_id')->nullable()
                ->constrained('invoice_number_series')->restrictOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('bank_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('draft');
            $table->string('invoice_number')->nullable();
            $table->string('variable_symbol', 10)->nullable();
            $table->date('issue_date');
            // Nullable: koncept nemusí mít splatnost — doplní ji issue()
            // ze settings.default_due_days (viz INVOICE_LIFECYCLE.md).
            $table->date('due_date')->nullable();
            $table->date('tax_date')->nullable();
            $table->char('currency', 3)->default('CZK');
            $table->bigInteger('subtotal_minor')->default(0);
            $table->bigInteger('vat_total_minor')->default(0);
            $table->bigInteger('total_minor')->default(0);
            $table->bigInteger('paid_amount_minor')->default(0);
            $table->text('note')->nullable();
            $table->text('internal_note')->nullable();
            $table->text('footer_text')->nullable();
            $table->json('supplier_snapshot')->nullable();
            $table->json('customer_snapshot')->nullable();
            $table->json('bank_account_snapshot')->nullable();
            $table->dateTime('issued_at')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'invoice_number']);
            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('issued_invoices');
    }
};
