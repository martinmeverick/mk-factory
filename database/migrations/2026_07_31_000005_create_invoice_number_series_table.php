<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoice_number_series', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('prefix', 10)->default('');
            $table->unsignedSmallInteger('year');
            $table->unsignedInteger('next_number')->default(1);
            $table->string('number_format')->default('{PREFIX}{YEAR}{NUMBER:4}');
            $table->timestamps();

            $table->unique(['organization_id', 'prefix', 'year']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_number_series');
    }
};
