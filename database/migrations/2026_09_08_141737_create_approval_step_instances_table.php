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
        Schema::create('approval_step_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignId('approval_instance_id')->constrained()->restrictOnDelete();
            $table->foreignId('workflow_step_id')->nullable()->constrained()->restrictOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('name');
            $table->string('approver_type', 30);
            $table->string('approver_value')->nullable();
            $table->string('approval_mode', 20);
            $table->unsignedInteger('required_approvals');
            $table->string('status', 20);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['approval_instance_id', 'step_order']);
            $table->index('status');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('approval_step_instances');
    }
};
