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
        Schema::table('intake_batches', function (Blueprint $table) {
            $table->foreignId('expense_claim_id')->nullable()->constrained()->nullOnDelete();
            $table->unique('expense_claim_id', 'intake_batches_expense_claim_unique');
        });

        Schema::table('document_intakes', function (Blueprint $table) {
            $table->foreignId('expense_item_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_item_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->unique('expense_item_id', 'document_intakes_expense_item_unique');
            $table->unique('expense_item_attachment_id', 'document_intakes_expense_attachment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_intakes', function (Blueprint $table) {
            $table->dropUnique('document_intakes_expense_item_unique');
            $table->dropUnique('document_intakes_expense_attachment_unique');
            $table->dropConstrainedForeignId('expense_item_attachment_id');
            $table->dropConstrainedForeignId('expense_item_id');
        });

        Schema::table('intake_batches', function (Blueprint $table) {
            $table->dropUnique('intake_batches_expense_claim_unique');
            $table->dropConstrainedForeignId('expense_claim_id');
        });
    }
};
