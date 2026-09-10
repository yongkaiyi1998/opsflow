<?php

namespace App\Http\Controllers;

use App\Models\ExpenseClaim;
use App\Services\ExpenseClaimService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ExpenseClaimSubmissionController extends Controller
{
    public function __invoke(Request $request, ExpenseClaim $expenseClaim, ExpenseClaimService $service): RedirectResponse
    {
        $service->submit($expenseClaim, $request->user());

        return redirect()->route('expense-claims.show', $expenseClaim)->with('success', 'Expense claim submitted for approval.');
    }
}
