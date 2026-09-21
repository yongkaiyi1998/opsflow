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
            $table->foreignId('purchase_request_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('purchase_request_attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->unique('purchase_request_id', 'document_intakes_purchase_request_unique');
            $table->unique('purchase_request_attachment_id', 'document_intakes_pr_attachment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_intakes', function (Blueprint $table) {
            $table->dropUnique('document_intakes_purchase_request_unique');
            $table->dropUnique('document_intakes_pr_attachment_unique');
            $table->dropConstrainedForeignId('purchase_request_attachment_id');
            $table->dropConstrainedForeignId('purchase_request_id');
        });
    }
};
