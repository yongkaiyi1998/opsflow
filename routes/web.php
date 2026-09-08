<?php

use App\Http\Controllers\AttachmentController;
use App\Http\Controllers\Auth\AuthenticatedSessionController;
use App\Http\Controllers\Auth\NewPasswordController;
use App\Http\Controllers\Auth\PasswordResetLinkController;
use App\Http\Controllers\DepartmentController;
use App\Http\Controllers\SpendCategoryController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VendorController;
use App\Http\Controllers\WorkflowRuleController;
use App\Http\Controllers\WorkflowRuleGroupController;
use App\Http\Controllers\WorkflowStepController;
use App\Http\Controllers\WorkflowTemplateController;
use App\Http\Controllers\WorkflowVersionController;
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
    Route::view('/dashboard', 'dashboard')->name('dashboard');
    Route::get('/attachments/{attachment}/download', [AttachmentController::class, 'download'])->name('attachments.download');
    Route::delete('/attachments/{attachment}', [AttachmentController::class, 'destroy'])->name('attachments.destroy');
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
