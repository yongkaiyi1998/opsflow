<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreExpenseReceiptIntakeRequest;
use App\IntakeDocumentType;
use App\Models\ExpenseClaim;
use App\Models\IntakeBatch;
use App\Services\ExpenseReceiptIntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ExpenseReceiptIntakeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        Gate::authorize('create', ExpenseClaim::class);
        $intakeBatches = IntakeBatch::query()
            ->whereBelongsTo(request()->user(), 'uploadedBy')
            ->whereHas('documentIntakes', fn ($query) => $query
                ->where('document_type', IntakeDocumentType::ExpenseReceipt->value))
            ->with(['documentIntakes:id,intake_batch_id,status'])
            ->latest()
            ->latest('id')
            ->paginate(15);

        return view('expense-receipt-intakes.index', compact('intakeBatches'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        Gate::authorize('create', ExpenseClaim::class);

        return view('expense-receipt-intakes.create', ['submissionKey' => Str::uuid()->toString()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(
        StoreExpenseReceiptIntakeRequest $request,
        ExpenseReceiptIntakeService $service,
    ): RedirectResponse {
        $batch = $service->createBatch(
            $request->file('documents', []),
            $request->string('submission_key')->toString(),
            $request->user(),
        );

        return redirect()->route('expense-receipt-intakes.show', $batch)
            ->with('success', 'Receipt intake batch uploaded.');
    }

    /**
     * Display the specified resource.
     */
    public function show(IntakeBatch $intakeBatch): View
    {
        Gate::authorize('view', $intakeBatch);
        $intakeBatch->load(['uploadedBy', 'documentIntakes', 'expenseClaim']);
        abort_unless($intakeBatch->documentIntakes->isNotEmpty()
            && $intakeBatch->documentIntakes->every(
                fn ($document): bool => $document->document_type === IntakeDocumentType::ExpenseReceipt,
            ), 404);

        return view('expense-receipt-intakes.show', compact('intakeBatch'));
    }
}
