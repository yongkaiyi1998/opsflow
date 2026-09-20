<?php

namespace App\Http\Controllers;

use App\Exceptions\AI\AiException;
use App\Exceptions\CategorySuggestionException;
use App\Http\Requests\SuggestSupplierInvoiceCategoryRequest;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Services\CategorySuggestionService;
use Illuminate\Http\RedirectResponse;
use JsonException;

class DocumentIntakeCategorySuggestionController extends Controller
{
    public function __invoke(
        SuggestSupplierInvoiceCategoryRequest $request,
        IntakeBatch $intakeBatch,
        DocumentIntake $documentIntake,
        CategorySuggestionService $suggestions,
    ): RedirectResponse {
        abort_unless($documentIntake->intake_batch_id === $intakeBatch->id, 404);

        try {
            $suggestion = $suggestions->suggestForSupplierInvoice($documentIntake, $request->user());
        } catch (AiException|CategorySuggestionException|JsonException) {
            return back()->withInput()->with('categorySuggestionError', 'Category suggestion is unavailable. You can still select a category manually.');
        }

        return back()->withInput()->with('categorySuggestion', $suggestion->forFormTarget('category_id'));
    }
}
