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
        Schema::table('document_intakes', function (Blueprint $table) {
            $table->foreignId('ai_interaction_id')->nullable()->constrained()->restrictOnDelete();
            $table->json('extraction_payload')->nullable();
            $table->json('extraction_warnings')->nullable();
            $table->string('extraction_prompt_version', 50)->nullable();
            $table->string('extraction_schema_version', 50)->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->timestamp('extracted_at')->nullable();
            $table->text('failure_reason')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_intakes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('ai_interaction_id');
            $table->dropColumn([
                'extraction_payload',
                'extraction_warnings',
                'extraction_prompt_version',
                'extraction_schema_version',
                'processing_started_at',
                'extracted_at',
                'failure_reason',
            ]);
        });
    }
};
