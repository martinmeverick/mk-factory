<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('code')->nullable();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('active');
            $table->string('external_id')->nullable();
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['organization_id', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
