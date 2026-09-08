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
        Schema::create('approval_instances', function (Blueprint $table) {
            $table->id();
            $table->morphs('approvable');
            $table->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_rule_group_id')->constrained()->restrictOnDelete();
            $table->string('status', 20);
            $table->unsignedInteger('current_step_order')->nullable();
            $table->json('workflow_context');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['approvable_type', 'approvable_id', 'status'], 'approval_instances_approvable_status_index');
            $table->index('status');
            $table->index('workflow_version_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_instances');
    }
};
