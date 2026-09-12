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
        Schema::table('approval_assignments', function (Blueprint $table) {
            $table->dropUnique('approval_assignments_step_approver_unique');
            $table->index(
                ['approval_step_instance_id', 'approver_id'],
                'approval_assignments_step_approver_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('approval_assignments', function (Blueprint $table) {
            $table->dropIndex('approval_assignments_step_approver_index');
            $table->unique(
                ['approval_step_instance_id', 'approver_id'],
                'approval_assignments_step_approver_unique',
            );
        });
    }
};
