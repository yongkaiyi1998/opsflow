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
        Schema::create('approval_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_instance_id')->constrained()->restrictOnDelete();
            $table->foreignId('approval_step_instance_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            $table->text('comment')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at');

            $table->index(['approval_instance_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_actions');
    }
};
