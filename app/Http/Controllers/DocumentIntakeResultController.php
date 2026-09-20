<?php

namespace App\Http\Controllers;

use App\DocumentIntakeStatus;
use App\IntakeDocumentType;
use App\MasterDataStatus;
use App\Models\Department;
use App\Models\DocumentIntake;
use App\Models\IntakeBatch;
use App\Models\SpendCategory;
use App\Models\Vendor;
use App\Services\DuplicateDetectionService;
use App\Services\VendorMatchingService;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class DocumentIntakeResultController extends Controller
{
    public function __invoke(
        IntakeBatch $intakeBatch,
        DocumentIntake $documentIntake,
        VendorMatchingService $vendorMatching,
        DuplicateDetectionService $duplicateDetection,
    ): View {
        Gate::authorize('view', $intakeBatch);
        Gate::authorize('view', $documentIntake);
        abort_unless($documentIntake->intakeBatch()->whereKey($intakeBatch->getKey())->exists(), 404);
        abort_unless($documentIntake->document_type === IntakeDocumentType::SupplierInvoice, 404);
        $documentIntake->load(['aiInteraction', 'supplierInvoice', 'supplierInvoiceAttachment', 'verifiedBy']);
        $vendorMatches = [];
        $duplicateMatches = [];

        if ($documentIntake->status === DocumentIntakeStatus::NeedsVerification
            && is_array($documentIntake->extraction_payload)) {
            $vendorMatches = $vendorMatching->match($documentIntake->extraction_payload['vendor_name'] ?? null);
            $duplicateMatches = $duplicateDetection->analyze($documentIntake, vendorMatches: $vendorMatches);
        }

        return view('invoice-intakes.documents.show', [
            'intakeBatch' => $intakeBatch,
            'documentIntake' => $documentIntake,
            'vendors' => Vendor::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
            'departments' => Department::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
            'categories' => SpendCategory::query()->where('status', MasterDataStatus::Active->value)->orderBy('name')->get(),
            'vendorMatches' => $vendorMatches,
            'duplicateMatches' => $duplicateMatches,
        ]);
    }
}
