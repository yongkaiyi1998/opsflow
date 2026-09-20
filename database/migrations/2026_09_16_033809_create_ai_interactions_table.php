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
        Schema::create('ai_interactions', function (Blueprint $table) {
            $table->id();
            $table->string('feature', 100);
            $table->string('provider', 50);
            $table->string('model', 150);
            $table->nullableMorphs('subject');
            $table->string('prompt_version', 50);
            $table->string('schema_version', 50)->nullable();
            $table->string('status', 20);
            $table->string('idempotency_key', 100)->nullable();
            $table->char('input_hash', 64)->nullable();
            $table->json('request_metadata')->nullable();
            $table->json('response_payload')->nullable();
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error_code', 100)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processing_started_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['feature', 'idempotency_key'], 'ai_interactions_feature_idempotency_unique');
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ai_interactions');
    }
};
