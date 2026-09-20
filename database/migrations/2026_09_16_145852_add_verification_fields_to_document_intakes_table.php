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
            $table->foreignId('supplier_invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('supplier_invoice_attachment_id')->nullable()->constrained('attachments')->restrictOnDelete();
            $table->foreignId('verified_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('verified_at')->nullable();

            $table->unique('supplier_invoice_id', 'document_intakes_supplier_invoice_unique');
            $table->unique('supplier_invoice_attachment_id', 'document_intakes_invoice_attachment_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('document_intakes', function (Blueprint $table) {
            $table->dropUnique('document_intakes_supplier_invoice_unique');
            $table->dropUnique('document_intakes_invoice_attachment_unique');
            $table->dropConstrainedForeignId('supplier_invoice_attachment_id');
            $table->dropConstrainedForeignId('supplier_invoice_id');
            $table->dropConstrainedForeignId('verified_by');
            $table->dropColumn('verified_at');
        });
    }
};
