<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePurchaseQuotationIntakeRequest;
use App\IntakeDocumentType;
use App\Models\IntakeBatch;
use App\Models\PurchaseRequest;
use App\Services\PurchaseQuotationIntakeService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PurchaseQuotationIntakeController extends Controller
{
    public function index(): View
    {
        Gate::authorize('create', PurchaseRequest::class);
        $batches = IntakeBatch::query()
            ->whereBelongsTo(request()->user(), 'uploadedBy')
            ->whereHas('documentIntakes', fn ($query) => $query
                ->where('document_type', IntakeDocumentType::PurchaseQuotation->value))
            ->with(['documentIntakes:id,intake_batch_id,status'])
            ->latest()->latest('id')->paginate(15);

        return view('purchase-quotation-intakes.index', compact('batches'));
    }

    public function create(): View
    {
        Gate::authorize('create', PurchaseRequest::class);

        return view('purchase-quotation-intakes.create', ['submissionKey' => Str::uuid()->toString()]);
    }

    public function store(StorePurchaseQuotationIntakeRequest $request, PurchaseQuotationIntakeService $service): RedirectResponse
    {
        $batch = $service->createBatch(
            $request->file('documents', []),
            $request->string('submission_key')->toString(),
            $request->user(),
        );

        return redirect()->route('purchase-quotation-intakes.show', $batch)
            ->with('success', 'Quotation intake batch uploaded.');
    }

    public function show(IntakeBatch $intakeBatch): View
    {
        Gate::authorize('view', $intakeBatch);
        $intakeBatch->load(['uploadedBy', 'documentIntakes']);
        abort_unless($intakeBatch->documentIntakes->isNotEmpty()
            && $intakeBatch->documentIntakes->every(
                fn ($document): bool => $document->document_type === IntakeDocumentType::PurchaseQuotation,
            ), 404);

        return view('purchase-quotation-intakes.show', compact('intakeBatch'));
    }
}
