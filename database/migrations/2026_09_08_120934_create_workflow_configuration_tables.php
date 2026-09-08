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
        Schema::create('workflow_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code', 50)->unique();
            $table->string('module_type', 30)->unique();
            $table->text('description')->nullable();
            $table->string('status', 20)->default('ACTIVE');
            $table->timestamps();
            $table->index(['module_type', 'status']);
        });

        Schema::create('workflow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_template_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 20)->default('DRAFT');
            $table->timestamp('effective_from')->nullable();
            $table->timestamp('effective_until')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('published_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->unique(['workflow_template_id', 'version']);
            $table->index(['workflow_template_id', 'status']);
        });

        Schema::create('workflow_rule_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_version_id')->constrained()->restrictOnDelete();
            $table->string('name');
            $table->unsignedInteger('priority');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->index(['workflow_version_id', 'priority']);
        });

        Schema::create('workflow_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_rule_group_id')->constrained()->restrictOnDelete();
            $table->string('field', 30);
            $table->string('operator', 5);
            $table->json('value');
            $table->timestamps();
            $table->index('workflow_rule_group_id');
        });

        Schema::create('workflow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workflow_rule_group_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('step_order');
            $table->string('name');
            $table->string('approver_type', 30);
            $table->string('approver_value')->nullable();
            $table->string('approval_mode', 20)->default('ANY');
            $table->unsignedInteger('minimum_approvals')->nullable();
            $table->unsignedInteger('sla_hours')->nullable();
            $table->timestamps();
            $table->unique(['workflow_rule_group_id', 'step_order']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('workflow_steps');
        Schema::dropIfExists('workflow_rules');
        Schema::dropIfExists('workflow_rule_groups');
        Schema::dropIfExists('workflow_versions');
        Schema::dropIfExists('workflow_templates');
    }
};
