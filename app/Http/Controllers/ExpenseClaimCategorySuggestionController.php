<?php

namespace App\Http\Controllers;

use App\Exceptions\AI\AiException;
use App\Exceptions\CategorySuggestionException;
use App\Http\Requests\SuggestExpenseClaimCategoryRequest;
use App\Models\ExpenseClaim;
use App\Services\CategorySuggestionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use JsonException;

class ExpenseClaimCategorySuggestionController extends Controller
{
    public function create(
        SuggestExpenseClaimCategoryRequest $request,
        CategorySuggestionService $suggestions,
    ): RedirectResponse {
        return $this->suggest($request, $suggestions);
    }

    public function update(
        SuggestExpenseClaimCategoryRequest $request,
        ExpenseClaim $expenseClaim,
        CategorySuggestionService $suggestions,
    ): RedirectResponse {
        return $this->suggest($request, $suggestions, $expenseClaim);
    }

    private function suggest(
        SuggestExpenseClaimCategoryRequest $request,
        CategorySuggestionService $suggestions,
        ?ExpenseClaim $expenseClaim = null,
    ): RedirectResponse {
        $validated = $request->validated();
        $index = (int) $validated['suggest_item_index'];
        $item = $validated['items'][$index] ?? null;

        if (! is_array($item)) {
            throw ValidationException::withMessages([
                'items' => 'The selected expense item is unavailable.',
            ]);
        }

        try {
            $suggestion = $suggestions->suggestForExpenseItem($item, $request->user(), $expenseClaim);
        } catch (AiException|CategorySuggestionException|JsonException) {
            return back()->withInput()->with('categorySuggestionError', 'Category suggestion is unavailable. You can still select a category manually.');
        }

        return back()->withInput()->with('categorySuggestion', $suggestion->forFormTarget("item-category-{$index}"));
    }
}
