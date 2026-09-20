<?php

use App\Http\Controllers\ApprovalController;
use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\DocumentIntakeCategorySuggestionController;
use App\Http\Controllers\DocumentIntakeExtractionController;
use App\Http\Controllers\DocumentIntakeOriginalController;
use App\Http\Controllers\DocumentIntakeResultController;
use App\Http\Controllers\DocumentIntakeVerificationController;
use App\Http\Controllers\ExpenseClaimCategorySuggestionController;
use App\Http\Controllers\ExpenseClaimController;
use App\Http\Controllers\ExpenseClaimLifecycleController;
use App\Http\Controllers\ExpenseClaimSubmissionController;
use App\Http\Controllers\ExpenseItemAttachmentController;
use App\Http\Controllers\ExpenseReceiptIntakeController;
use App\Http\Controllers\ExpenseReceiptIntakeResultController;
use App\Http\Controllers\InvoiceIntakeController;
use App\Http\Controllers\NotificationCenterController;
use App\Http\Controllers\PurchaseRequestAttachmentController;
use App\Http\Controllers\PurchaseRequestCategorySuggestionController;
use App\Http\Controllers\PurchaseRequestController;
use App\Http\Controllers\PurchaseRequestLifecycleController;
use App\Http\Controllers\PurchaseRequestSubmissionController;
use App\Http\Controllers\RecordAnalysisController;
use App\Http\Controllers\SpendCategoryController;
use App\Http\Controllers\SupplierInvoiceAttachmentController;
use App\Http\Controllers\SupplierInvoiceController;
use App\Http\Controllers\SupplierInvoiceLifecycleController;
use App\Http\Controllers\SupplierInvoiceSubmissionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WorkflowExplanationController;
use App\Http\Controllers\WorkflowRuleController;
use App\Http\Controllers\WorkflowRuleGroupController;
use App\Http\Controllers\WorkflowStepController;
use App\Http\Controllers\WorkflowTemplateController;
use App\Http\Controllers\WorkflowVersionController;
use App\Http\Controllers\WritingAssistantController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/dashboard');

Route::middleware('guest')->group(function (): void {
    Route::get('/login', [AuthenticatedSessionController::class, 'create'])->name('login');
    Route::post('/login', [AuthenticatedSessionController::class, 'store'])->middleware('throttle:30,1');
    Route::get('/forgot-password', [PasswordResetLinkController::class, 'create'])->name('password.request');
    Route::post('/forgot-password', [PasswordResetLinkController::class, 'store'])->middleware('throttle:5,1')->name('password.email');
    Route::get('/reset-password/{token}', [NewPasswordController::class, 'create'])->name('password.reset');
    Route::post('/reset-password', [NewPasswordController::class, 'store'])->middleware('throttle:5,1')->name('password.update');
});

Route::post('/logout', [AuthenticatedSessionController::class, 'destroy'])->middleware('auth')->name('logout');
Route::middleware(['auth', 'auth.session', 'active'])->group(function (): void {
    Route::get('/dashboard', DashboardController::class)->name('dashboard');
    Route::get('/notifications', [NotificationCenterController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/{notification}/read', [NotificationCenterController::class, 'markRead'])->name('notifications.read');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
    Route::get('/approvals', [ApprovalController::class, 'index'])->name('approvals.index');
    Route::get('/approvals/{approval_assignment}', [ApprovalController::class, 'show'])->name('approvals.show');
    Route::post('/approval-assignments/{approval_assignment}/approve', [ApprovalController::class, 'approve'])->name('approval-assignments.approve');
    Route::post('/approval-assignments/{approval_assignment}/reject', [ApprovalController::class, 'reject'])->name('approval-assignments.reject');
    Route::post('/approval-assignments/{approval_assignment}/request-changes', [ApprovalController::class, 'requestChanges'])->name('approval-assignments.request-changes');
    Route::post('/approval-assignments/{approval_assignment}/workflow-explanation', WorkflowExplanationController::class)->name('approval-assignments.workflow-explanation');
    Route::post('/approval-assignments/{approval_assignment}/writing/{writing_action}', [WritingAssistantController::class, 'approvalComment'])
        ->whereIn('writing_action', ['request_changes', 'reject'])->name('approval-assignments.writing-assistance');
    Route::post('purchase-requests/writing-assistance', [WritingAssistantController::class, 'purchaseRequest'])->name('purchase-requests.writing-assistance.create');
    Route::match(['post', 'put'], 'purchase-requests/{purchase_request}/writing-assistance', [WritingAssistantController::class, 'purchaseRequest'])->name('purchase-requests.writing-assistance.update');
    Route::post('purchase-requests/category-suggestion', [PurchaseRequestCategorySuggestionController::class, 'create'])
        ->name('purchase-requests.category-suggestion.create');
    Route::match(['post', 'put'], 'purchase-requests/{purchase_request}/category-suggestion', [PurchaseRequestCategorySuggestionController::class, 'update'])
        ->name('purchase-requests.category-suggestion.update');
    Route::resource('purchase-requests', PurchaseRequestController::class);
    Route::post('purchase-requests/{purchase_request}/submit', [PurchaseRequestSubmissionController::class, 'store'])->name('purchase-requests.submit');
    Route::post('purchase-requests/{purchase_request}/ai-analysis', [RecordAnalysisController::class, 'purchaseRequest'])->name('purchase-requests.ai-analysis');
    Route::post('purchase-requests/{purchase_request}/resubmit', [PurchaseRequestLifecycleController::class, 'resubmit'])->name('purchase-requests.resubmit');
    Route::post('purchase-requests/{purchase_request}/withdraw', [PurchaseRequestLifecycleController::class, 'withdraw'])->name('purchase-requests.withdraw');
    Route::post('purchase-requests/{purchase_request}/attachments', [PurchaseRequestAttachmentController::class, 'store'])->name('purchase-request-attachments.store');
    Route::resource('supplier-invoices', SupplierInvoiceController::class);
    Route::post('supplier-invoices/{supplier_invoice}/submit', SupplierInvoiceSubmissionController::class)->name('supplier-invoices.submit');
    Route::post('supplier-invoices/{supplier_invoice}/ai-analysis', [RecordAnalysisController::class, 'supplierInvoice'])->name('supplier-invoices.ai-analysis');
    Route::post('supplier-invoices/{supplier_invoice}/resubmit', [SupplierInvoiceLifecycleController::class, 'resubmit'])->name('supplier-invoices.resubmit');
    Route::post('supplier-invoices/{supplier_invoice}/withdraw', [SupplierInvoiceLifecycleController::class, 'withdraw'])->name('supplier-invoices.withdraw');
    Route::post('supplier-invoices/{supplier_invoice}/attachments', [SupplierInvoiceAttachmentController::class, 'store'])->name('supplier-invoice-attachments.store');
    Route::resource('invoice-intakes', InvoiceIntakeController::class)
        ->parameters(['invoice-intakes' => 'intake_batch'])
        ->only(['index', 'create', 'store', 'show']);
    Route::get('invoice-intakes/{intake_batch}/documents/{document_intake}/original', DocumentIntakeOriginalController::class)
        ->scopeBindings()
        ->name('invoice-intakes.documents.original');
    Route::get('invoice-intakes/{intake_batch}/documents/{document_intake}', DocumentIntakeResultController::class)
        ->scopeBindings()
        ->name('invoice-intakes.documents.show');
    Route::post('invoice-intakes/{intake_batch}/documents/{document_intake}/extract', DocumentIntakeExtractionController::class)
        ->scopeBindings()
        ->name('invoice-intakes.documents.extract');
    Route::post('invoice-intakes/{intake_batch}/documents/{document_intake}/category-suggestion', DocumentIntakeCategorySuggestionController::class)
        ->scopeBindings()
        ->name('invoice-intakes.documents.category-suggestion');
    Route::post('invoice-intakes/{intake_batch}/documents/{document_intake}/verify', DocumentIntakeVerificationController::class)
        ->scopeBindings()
        ->name('invoice-intakes.documents.verify');
    Route::resource('expense-receipt-intakes', ExpenseReceiptIntakeController::class)
        ->parameters(['expense-receipt-intakes' => 'intake_batch'])
        ->only(['index', 'create', 'store', 'show']);
    Route::get('expense-receipt-intakes/{intake_batch}/documents/{document_intake}/original', DocumentIntakeOriginalController::class)
        ->scopeBindings()
        ->name('expense-receipt-intakes.documents.original');
    Route::get('expense-receipt-intakes/{intake_batch}/documents/{document_intake}', ExpenseReceiptIntakeResultController::class)
        ->scopeBindings()
        ->name('expense-receipt-intakes.documents.show');
    Route::post('expense-receipt-intakes/{intake_batch}/documents/{document_intake}/extract', DocumentIntakeExtractionController::class)
        ->scopeBindings()
        ->name('expense-receipt-intakes.documents.extract');
    Route::post('expense-claims/category-suggestion', [ExpenseClaimCategorySuggestionController::class, 'create'])
        ->name('expense-claims.category-suggestion.create');
    Route::match(['post', 'put'], 'expense-claims/{expense_claim}/category-suggestion', [ExpenseClaimCategorySuggestionController::class, 'update'])
        ->name('expense-claims.category-suggestion.update');
    Route::post('expense-claims/writing-assistance', [WritingAssistantController::class, 'expenseClaim'])->name('expense-claims.writing-assistance.create');
    Route::match(['post', 'put'], 'expense-claims/{expense_claim}/writing-assistance', [WritingAssistantController::class, 'expenseClaim'])->name('expense-claims.writing-assistance.update');
    Route::resource('expense-claims', ExpenseClaimController::class);
    Route::post('expense-claims/{expense_claim}/submit', ExpenseClaimSubmissionController::class)->name('expense-claims.submit');
    Route::post('expense-claims/{expense_claim}/ai-analysis', [RecordAnalysisController::class, 'expenseClaim'])->name('expense-claims.ai-analysis');
    Route::post('expense-claims/{expense_claim}/resubmit', [ExpenseClaimLifecycleController::class, 'resubmit'])->name('expense-claims.resubmit');
    Route::post('expense-claims/{expense_claim}/withdraw', [ExpenseClaimLifecycleController::class, 'withdraw'])->name('expense-claims.withdraw');
    Route::post('approvals/{approval_assignment}/ai-analysis', [RecordAnalysisController::class, 'approval'])->name('approvals.ai-analysis');
    Route::post('expense-items/{expense_item}/attachments', [ExpenseItemAttachmentController::class, 'store'])->name('expense-item-attachments.store');
    Route::resource('departments', DepartmentController::class)->except(['show', 'destroy']);
    Route::resource('spend-categories', SpendCategoryController::class)->except(['show', 'destroy']);
    Route::resource('vendors', VendorController::class)->except(['show', 'destroy']);
    Route::resource('users', UserController::class)->except(['show', 'destroy']);
    Route::resource('workflow-templates', WorkflowTemplateController::class)->except('destroy');
    Route::post('workflow-templates/{workflow_template}/versions', [WorkflowVersionController::class, 'store'])->name('workflow-versions.store');
    Route::get('workflow-versions/{workflow_version}/edit', [WorkflowVersionController::class, 'edit'])->name('workflow-versions.edit');
    Route::delete('workflow-versions/{workflow_version}', [WorkflowVersionController::class, 'destroy'])->name('workflow-versions.destroy');
    Route::post('workflow-versions/{workflow_version}/publish', [WorkflowVersionController::class, 'publish'])->name('workflow-versions.publish');
    Route::post('workflow-versions/{workflow_version}/clone', [WorkflowVersionController::class, 'clone'])->name('workflow-versions.clone');
    Route::post('workflow-versions/{workflow_version}/rule-groups', [WorkflowRuleGroupController::class, 'store'])->name('workflow-rule-groups.store');
    Route::put('workflow-rule-groups/{workflow_rule_group}', [WorkflowRuleGroupController::class, 'update'])->name('workflow-rule-groups.update');
    Route::delete('workflow-rule-groups/{workflow_rule_group}', [WorkflowRuleGroupController::class, 'destroy'])->name('workflow-rule-groups.destroy');
    Route::post('workflow-rule-groups/{workflow_rule_group}/rules', [WorkflowRuleController::class, 'store'])->name('workflow-rules.store');
    Route::put('workflow-rules/{workflow_rule}', [WorkflowRuleController::class, 'update'])->name('workflow-rules.update');
    Route::delete('workflow-rules/{workflow_rule}', [WorkflowRuleController::class, 'destroy'])->name('workflow-rules.destroy');
    Route::post('workflow-rule-groups/{workflow_rule_group}/steps', [WorkflowStepController::class, 'store'])->name('workflow-steps.store');
    Route::put('workflow-steps/{workflow_step}', [WorkflowStepController::class, 'update'])->name('workflow-steps.update');
    Route::delete('workflow-steps/{workflow_step}', [WorkflowStepController::class, 'destroy'])->name('workflow-steps.destroy');
});
