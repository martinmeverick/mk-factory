<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('received_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('contact_id')->constrained()->restrictOnDelete();
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->string('supplier_invoice_number')->nullable();
            $table->string('variable_symbol', 10)->nullable();
            $table->date('issue_date')->nullable();
            $table->date('received_date');
            $table->date('due_date')->nullable();
            $table->char('currency', 3)->default('CZK');
            $table->bigInteger('total_minor');
            $table->bigInteger('vat_minor')->nullable();
            $table->string('status')->default('received');
            $table->text('note')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'status']);
            $table->index(['organization_id', 'due_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('received_invoices');
    }
};
