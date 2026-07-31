<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->string('name');
            $table->string('ico', 20)->nullable();
            $table->string('dic', 20)->nullable();
            $table->string('street')->nullable();
            $table->string('city')->nullable();
            $table->string('zip')->nullable();
            $table->string('country', 2)->default('CZ');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->index(['organization_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contacts');
    }
};
