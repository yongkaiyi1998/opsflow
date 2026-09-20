<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreIntakeBatchRequest;
use App\Models\IntakeBatch;
use App\Services\InvoiceIntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class InvoiceIntakeController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        Gate::authorize('viewAny', IntakeBatch::class);
        $intakeBatches = IntakeBatch::query()
            ->with([
                'uploadedBy',
                'documentIntakes:id,intake_batch_id,status',
            ])
            ->latest()
            ->latest('id')
            ->paginate(15);

        return view('invoice-intakes.index', compact('intakeBatches'));
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        Gate::authorize('create', IntakeBatch::class);

        return view('invoice-intakes.create', ['submissionKey' => Str::uuid()->toString()]);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreIntakeBatchRequest $request, InvoiceIntakeService $service): RedirectResponse
    {
        $batch = $service->createBatch(
            $request->file('documents', []),
            $request->string('submission_key')->toString(),
            $request->user(),
        );

        return redirect()->route('invoice-intakes.show', $batch)
            ->with('success', 'Invoice intake batch uploaded.');
    }

    /**
     * Display the specified resource.
     */
    public function show(IntakeBatch $intakeBatch): View
    {
        Gate::authorize('view', $intakeBatch);
        $intakeBatch->load(['uploadedBy', 'documentIntakes']);

        return view('invoice-intakes.show', compact('intakeBatch'));
    }
}
