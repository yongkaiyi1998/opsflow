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
        Schema::create('approval_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_step_instance_id')->constrained()->restrictOnDelete();
            $table->foreignId('approver_id')->constrained('users')->restrictOnDelete();
            $table->string('status', 20);
            $table->timestamp('assigned_at');
            $table->timestamp('acted_at')->nullable();
            $table->foreignId('delegated_from_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['approval_step_instance_id', 'approver_id']);
            $table->index(['approver_id', 'status']);
            $table->index(['approval_step_instance_id', 'status'], 'approval_assignments_step_status_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_assignments');
    }
};
