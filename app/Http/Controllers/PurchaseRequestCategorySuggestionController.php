<?php

namespace App\Http\Controllers;

use App\Exceptions\AI\AiException;
use App\Exceptions\CategorySuggestionException;
use App\Http\Requests\SuggestPurchaseRequestCategoryRequest;
use App\Models\PurchaseRequest;
use App\Services\CategorySuggestionService;
use Illuminate\Http\RedirectResponse;
use JsonException;

class PurchaseRequestCategorySuggestionController extends Controller
{
    public function create(
        SuggestPurchaseRequestCategoryRequest $request,
        CategorySuggestionService $suggestions,
    ): RedirectResponse {
        return $this->suggest($request, $suggestions);
    }

    public function update(
        SuggestPurchaseRequestCategoryRequest $request,
        PurchaseRequest $purchaseRequest,
        CategorySuggestionService $suggestions,
    ): RedirectResponse {
        return $this->suggest($request, $suggestions, $purchaseRequest);
    }

    private function suggest(
        SuggestPurchaseRequestCategoryRequest $request,
        CategorySuggestionService $suggestions,
        ?PurchaseRequest $purchaseRequest = null,
    ): RedirectResponse {
        try {
            $suggestion = $suggestions->suggestForPurchaseRequest($request->validated(), $request->user(), $purchaseRequest);
        } catch (AiException|CategorySuggestionException|JsonException) {
            return back()->withInput()->with('categorySuggestionError', 'Category suggestion is unavailable. You can still select a category manually.');
        }

        return back()->withInput()->with('categorySuggestion', $suggestion->forFormTarget('category_id'));
    }
}
