<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('document_intakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('intake_batch_id')->constrained()->restrictOnDelete();
            $table->string('original_name');
            $table->string('disk', 50);
            $table->string('path')->unique();
            $table->string('mime_type', 100);
            $table->unsignedBigInteger('size');
            $table->string('document_type', 30);
            $table->string('status', 30);
            $table->timestamps();

            $table->index(['intake_batch_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_intakes');
    }
};
