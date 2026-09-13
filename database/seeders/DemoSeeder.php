<?php

namespace Database\Seeders;

use App\ApprovalAssignmentStatus;
use App\ApprovalInstanceStatus;
use App\ApprovalMode;
use App\ApproverType;
use App\MasterDataStatus;
use App\Models\ApprovalAssignment;
use App\Models\Department;
use App\Models\ExpenseClaim;
use App\Models\SpendCategory;
use App\Models\User;
use App\Models\Vendor;
use App\Models\WorkflowTemplate;
use App\Services\ApprovalService;
use App\Services\AttachmentService;
use App\Services\ExpenseClaimService;
use App\Services\PurchaseRequestService;
use App\Services\SupplierInvoiceService;
use App\Services\WorkflowConfigurationService;
use App\UserRole;
use App\UserStatus;
use App\WorkflowModuleType;
use App\WorkflowRuleField;
use App\WorkflowRuleOperator;
use App\WorkflowVersionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use LogicException;

class DemoSeeder extends Seeder
{
    private const PASSWORD = 'OpsFlowDemo!';

    /** @var array<string, User> */
    private array $users;

    /** @var array<string, Department> */
    private array $departments;

    /** @var array<string, SpendCategory> */
    private array $categories;

    /** @var array<string, Vendor> */
    private array $vendors;

    public function run(): void
    {
        if ($this->alreadySeeded()) {
            $this->command?->info('OpsFlow demo data is already present.');

            return;
        }

        $this->ensureDemoNamespaceIsAvailable();
        $this->seedOrganization();
        $this->seedMasterData();
        $this->seedWorkflows();
        $this->seedPurchaseRequests();
        $this->seedSupplierInvoices();
        $this->seedExpenseClaims();
    }

    private function alreadySeeded(): bool
    {
        return User::query()->whereIn('email', $this->demoEmails())->count() === count($this->demoEmails())
            && WorkflowTemplate::query()->whereIn('code', $this->workflowCodes())->count() === count($this->workflowCodes());
    }

    private function ensureDemoNamespaceIsAvailable(): void
    {
        if (User::query()->whereIn('email', $this->demoEmails())->exists()
            || WorkflowTemplate::query()->whereIn('code', $this->workflowCodes())->exists()) {
            throw new LogicException('Partial OpsFlow demo data already exists. Run php artisan migrate:fresh --seed to rebuild it safely.');
        }
    }

    private function seedOrganization(): void
    {
        $this->departments = [
            'finance' => Department::create(['name' => 'Finance', 'code' => 'FIN', 'status' => MasterDataStatus::Active]),
            'it' => Department::create(['name' => 'Information Technology', 'code' => 'IT', 'status' => MasterDataStatus::Active]),
            'operations' => Department::create(['name' => 'Operations', 'code' => 'OPS', 'status' => MasterDataStatus::Active]),
        ];

        $password = Hash::make(self::PASSWORD);
        $this->users = [
            'admin' => $this->createUser('Morgan Lee', 'admin@opsflow.test', UserRole::Admin, 'operations', $password),
            'director' => $this->createUser('Aisha Rahman', 'director@opsflow.test', UserRole::Employee, 'operations', $password),
            'finance_lead' => $this->createUser('Priya Nair', 'finance@opsflow.test', UserRole::Finance, 'finance', $password),
            'finance_analyst' => $this->createUser('Daniel Wong', 'analyst@opsflow.test', UserRole::Finance, 'finance', $password),
            'it_manager' => $this->createUser('Jordan Lim', 'it.manager@opsflow.test', UserRole::Employee, 'it', $password),
            'it_employee' => $this->createUser('Alex Tan', 'employee@opsflow.test', UserRole::Employee, 'it', $password),
            'operations_manager' => $this->createUser('Sara Ibrahim', 'operations.manager@opsflow.test', UserRole::Employee, 'operations', $password),
            'operations_employee' => $this->createUser('Noah Chen', 'operations.employee@opsflow.test', UserRole::Employee, 'operations', $password),
        ];

        foreach ([
            'finance_lead' => 'director',
            'finance_analyst' => 'finance_lead',
            'it_manager' => 'director',
            'it_employee' => 'it_manager',
            'operations_manager' => 'director',
            'operations_employee' => 'operations_manager',
        ] as $employee => $manager) {
            $this->users[$employee]->forceFill(['manager_id' => $this->users[$manager]->id])->save();
        }

        $this->departments['finance']->update(['manager_id' => $this->users['finance_lead']->id]);
        $this->departments['it']->update(['manager_id' => $this->users['it_manager']->id]);
        $this->departments['operations']->update(['manager_id' => $this->users['operations_manager']->id]);
    }

    private function createUser(string $name, string $email, UserRole $role, string $department, string $password): User
    {
        return User::forceCreate([
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
            'password' => $password,
            'role' => $role,
            'status' => UserStatus::Active,
            'department_id' => $this->departments[$department]->id,
        ]);
    }

    private function seedMasterData(): void
    {
        $this->categories = [
            'it' => SpendCategory::create(['name' => 'IT Equipment', 'code' => 'IT-EQUIP', 'status' => MasterDataStatus::Active]),
            'software' => SpendCategory::create(['name' => 'Software & Subscriptions', 'code' => 'SOFTWARE', 'status' => MasterDataStatus::Active]),
            'office' => SpendCategory::create(['name' => 'Office & Facilities', 'code' => 'OFFICE', 'status' => MasterDataStatus::Active]),
            'travel' => SpendCategory::create(['name' => 'Business Travel', 'code' => 'TRAVEL', 'status' => MasterDataStatus::Active]),
            'meals' => SpendCategory::create(['name' => 'Meals & Entertainment', 'code' => 'MEALS', 'status' => MasterDataStatus::Active]),
        ];

        $this->vendors = [
            'technology' => $this->createVendor('Northstar Technology Sdn Bhd', 'NORTHSTAR', 'accounts@northstar.example'),
            'office' => $this->createVendor('Workspace Supply Co', 'WORKSPACE', 'billing@workspace.example'),
            'cloud' => $this->createVendor('Nimbus Cloud Services', 'NIMBUS', 'finance@nimbus.example'),
        ];
    }

    private function createVendor(string $name, string $code, string $email): Vendor
    {
        return Vendor::create([
            'name' => $name,
            'code' => $code,
            'email' => $email,
            'phone' => '+60 3-5550 1200',
            'status' => MasterDataStatus::Active,
        ]);
    }

    private function seedWorkflows(): void
    {
        $this->createWorkflow('Purchase Request Approval', 'PR-APPROVAL', WorkflowModuleType::PurchaseRequest, [
            $this->group('IT High Value', 10, [
                [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThanOrEqual, '10000.00'],
                [WorkflowRuleField::Category, WorkflowRuleOperator::Equal, $this->categories['it']->id],
            ], [
                ['Requester manager', ApproverType::RequesterManager, null],
                ['Finance review', ApproverType::Role, UserRole::Finance->value],
                ['Director approval', ApproverType::SpecificUser, (string) $this->users['director']->id],
            ]),
            $this->group('Standard High Value', 20, [
                [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThanOrEqual, '10000.00'],
            ], [
                ['Requester manager', ApproverType::RequesterManager, null],
                ['Finance review', ApproverType::Role, UserRole::Finance->value],
            ]),
            $this->group('Default', 100, [], [
                ['Requester manager', ApproverType::RequesterManager, null],
            ], true),
        ]);

        $this->createWorkflow('Supplier Invoice Approval', 'INV-APPROVAL', WorkflowModuleType::SupplierInvoice, [
            $this->group('Director for High Value', 10, [
                [WorkflowRuleField::Amount, WorkflowRuleOperator::GreaterThanOrEqual, '25000.00'],
            ], [
                ['Department manager', ApproverType::DepartmentManager, null],
                ['Finance review', ApproverType::Role, UserRole::Finance->value],
                ['Director approval', ApproverType::SpecificUser, (string) $this->users['director']->id],
            ]),
            $this->group('Department Manager + Finance', 100, [], [
                ['Department manager', ApproverType::DepartmentManager, null],
                ['Finance review', ApproverType::Role, UserRole::Finance->value],
            ], true),
        ]);

        $this->createWorkflow('Expense Claim Approval', 'EXP-APPROVAL', WorkflowModuleType::ExpenseClaim, [
            $this->group('Requester Manager + Finance', 100, [], [
                ['Requester manager', ApproverType::RequesterManager, null],
                ['Finance review', ApproverType::Role, UserRole::Finance->value],
            ], true),
        ]);
    }

    /**
     * @param  list<array{WorkflowRuleField, WorkflowRuleOperator, string|int}>  $rules
     * @param  list<array{string, ApproverType, ?string}>  $steps
     * @return array{name: string, priority: int, is_default: bool, rules: array, steps: array}
     */
    private function group(string $name, int $priority, array $rules, array $steps, bool $default = false): array
    {
        return compact('name', 'priority', 'rules', 'steps') + ['is_default' => $default];
    }

    /** @param list<array{name: string, priority: int, is_default: bool, rules: array, steps: array}> $groups */
    private function createWorkflow(string $name, string $code, WorkflowModuleType $moduleType, array $groups): void
    {
        $template = WorkflowTemplate::create([
            'name' => $name,
            'code' => $code,
            'module_type' => $moduleType,
            'description' => "Demo approval routing for {$moduleType->label()} records.",
            'status' => MasterDataStatus::Active,
        ]);
        $version = $template->versions()->forceCreate([
            'version' => 1,
            'status' => WorkflowVersionStatus::Draft,
            'created_by' => $this->users['admin']->id,
        ]);

        foreach ($groups as $definition) {
            $group = $version->ruleGroups()->create([
                'name' => $definition['name'],
                'priority' => $definition['priority'],
                'is_default' => $definition['is_default'],
            ]);

            foreach ($definition['rules'] as [$field, $operator, $value]) {
                $group->rules()->create(['field' => $field, 'operator' => $operator, 'value' => $value]);
            }

            foreach ($definition['steps'] as $index => [$stepName, $approverType, $approverValue]) {
                $group->steps()->create([
                    'step_order' => $index + 1,
                    'name' => $stepName,
                    'approver_type' => $approverType,
                    'approver_value' => $approverValue,
                    'approval_mode' => ApprovalMode::Any,
                ]);
            }
        }

        app(WorkflowConfigurationService::class)->publish($version, $this->users['admin']);
    }

    private function seedPurchaseRequests(): void
    {
        $service = app(PurchaseRequestService::class);
        $approvals = app(ApprovalService::class);
        $employee = $this->users['it_employee'];
        $manager = $this->users['it_manager'];

        $service->create($this->purchasePayload('Ergonomic workstation refresh', 'Replace worn chairs and monitor arms.', 'office', 'office', [['Ergonomic chair', 5, '780.00'], ['Monitor arm', 5, '180.00']], '288.00'), $employee);

        $pending = $service->create($this->purchasePayload('Engineering laptop fleet', 'Refresh laptops for the growing platform team.', 'it', 'technology', [['Developer laptop', 12, '1850.00'], ['USB-C dock', 12, '210.00']], '1483.20'), $employee);
        $service->submit($pending, $employee);

        $changes = $service->create($this->purchasePayload('Developer conference passes', 'Conference attendance and workshop access.', 'travel', 'office', [['Conference pass', 4, '900.00']], '0.00'), $employee);
        $service->submit($changes, $employee);
        $approvals->requestChanges($this->pendingAssignment($changes, $manager), $manager, 'Add attendee names and expected project outcomes.');

        $resubmitted = $service->create($this->purchasePayload('Replacement network switches', 'Replace two end-of-life access switches.', 'it', 'technology', [['Managed network switch', 2, '2500.00']], '300.00'), $employee);
        $service->submit($resubmitted, $employee);
        $approvals->requestChanges($this->pendingAssignment($resubmitted, $manager), $manager, 'Confirm the included support term.');
        $resubmitted->refresh();
        $service->update($resubmitted, [
            ...$this->purchasePayload('Replacement network switches', 'Replace two end-of-life switches with three years of support.', 'it', 'technology', [['Managed network switch with support', 2, '2550.00']], '306.00'),
            'lock_version' => $resubmitted->lock_version,
        ], $employee);
        $service->resubmit($resubmitted, $employee, 'Support coverage added.');

        $withdrawn = $service->create($this->purchasePayload('Temporary design software licences', 'Short-term licences for a completed design sprint.', 'software', 'cloud', [['Design software licence', 10, '120.00']], '72.00'), $employee);
        $withdrawn = $service->submit($withdrawn, $employee);
        $approvals->withdraw($withdrawn, $employee, 'The design sprint no longer requires these licences.');

        $approved = $service->create($this->purchasePayload('Shared meeting room display', 'Replace the unreliable main meeting room display.', 'office', 'office', [['4K meeting room display', 1, '3200.00']], '192.00'), $employee);
        $service->submit($approved, $employee);
        $approvals->approve($this->pendingAssignment($approved, $manager), $manager, 'Approved for the shared meeting room.');
    }

    /** @param list<array{string, int, string}> $items */
    private function purchasePayload(string $title, string $description, string $category, string $vendor, array $items, string $tax): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'category_id' => $this->categories[$category]->id,
            'vendor_id' => $this->vendors[$vendor]->id,
            'needed_by_date' => today()->addDays(21)->toDateString(),
            'tax_amount' => $tax,
            'items' => array_map(fn (array $item): array => ['description' => $item[0], 'quantity' => $item[1], 'unit_price' => $item[2]], $items),
        ];
    }

    private function seedSupplierInvoices(): void
    {
        $service = app(SupplierInvoiceService::class);
        $approvals = app(ApprovalService::class);
        $finance = $this->users['finance_analyst'];
        $financeLead = $this->users['finance_lead'];

        $draft = $service->create($this->invoicePayload('NS-2026-1048', 'technology', 'it', 'software', 'Annual endpoint security subscription.', [['Endpoint security subscription', '250.0000', '18.00']], '270.00'), $finance);
        $this->attach($draft, $finance, 'endpoint-security-invoice.pdf');

        $highValue = $service->create($this->invoicePayload('NS-2026-1092', 'technology', 'it', 'it', 'Equipment for the office network refresh.', [['Core network appliance', '2.0000', '16500.00']], '1980.00'), $finance);
        $this->attach($highValue, $finance, 'network-refresh-invoice.pdf');
        $service->submit($highValue, $finance);
        $approvals->approve($this->pendingAssignment($highValue, $this->users['it_manager']), $this->users['it_manager'], 'Matches the approved infrastructure plan.');

        $approved = $service->create($this->invoicePayload('NC-2026-0815', 'cloud', 'finance', 'software', 'Monthly cloud platform subscription and usage.', [['Platform subscription', '1.0000', '1500.00'], ['Usage units', '42.5000', '12.00']], '120.60'), $finance);
        $this->attach($approved, $finance, 'cloud-services-invoice.pdf');
        $service->submit($approved, $finance);
        $approvals->approve($this->pendingAssignment($approved, $financeLead), $financeLead, 'Department review complete.');
        $approvals->approve($this->pendingAssignment($approved, $financeLead), $financeLead, 'Invoice verified against the service statement.');
    }

    /** @param list<array{string, string, string}> $items */
    private function invoicePayload(string $number, string $vendor, string $department, string $category, string $description, array $items, string $tax): array
    {
        return [
            'invoice_no' => $number,
            'vendor_id' => $this->vendors[$vendor]->id,
            'department_id' => $this->departments[$department]->id,
            'category_id' => $this->categories[$category]->id,
            'invoice_date' => today()->subDays(7)->toDateString(),
            'due_date' => today()->addDays(14)->toDateString(),
            'description' => $description,
            'tax_amount' => $tax,
            'items' => array_map(fn (array $item): array => ['description' => $item[0], 'quantity' => $item[1], 'unit_price' => $item[2]], $items),
        ];
    }

    private function seedExpenseClaims(): void
    {
        $service = app(ExpenseClaimService::class);
        $approvals = app(ApprovalService::class);
        $employee = $this->users['it_employee'];
        $manager = $this->users['it_manager'];

        $service->create($this->claimPayload('Customer discovery workshop', 'Travel and meals from the customer workshop.', [['City rail fare', 'travel', '48.60', '2.75'], ['Team dinner', 'meals', '186.40', '11.18']]), $employee);

        $managerReview = $service->create($this->claimPayload('Regional office visit', 'Expenses from the regional planning visit.', [['Hotel accommodation', 'travel', '680.00', '40.80'], ['Airport transfer', 'travel', '92.50', '5.55']]), $employee);
        $this->attachClaimReceipts($managerReview, $employee);
        $service->submit($managerReview, $employee);

        $financeReview = $service->create($this->claimPayload('Cloud architecture workshop', 'Expenses from the cloud architecture workshop.', [['Workshop hotel', 'travel', '520.00', '31.20'], ['Client refreshments', 'meals', '138.00', '8.28']]), $employee);
        $this->attachClaimReceipts($financeReview, $employee);
        $service->submit($financeReview, $employee);
        $approvals->approve($this->pendingAssignment($financeReview, $manager), $manager, 'Workshop attendance confirmed.');

        $approved = $service->create($this->claimPayload('Data centre maintenance visit', 'Transport and meals during scheduled maintenance.', [['Return rail ticket', 'travel', '124.80', '7.49'], ['Maintenance team meal', 'meals', '96.20', '5.77']]), $employee);
        $this->attachClaimReceipts($approved, $employee);
        $service->submit($approved, $employee);
        $approvals->approve($this->pendingAssignment($approved, $manager), $manager, 'Maintenance visit confirmed.');
        $approvals->approve($this->pendingAssignment($approved, $this->users['finance_lead']), $this->users['finance_lead'], 'Receipts checked and claim approved.');
    }

    /** @param list<array{string, string, string, string}> $items */
    private function claimPayload(string $title, string $description, array $items): array
    {
        return [
            'title' => $title,
            'description' => $description,
            'items' => array_map(fn (array $item): array => [
                'description' => $item[0],
                'merchant' => $item[0] === 'Team dinner' ? 'Kota Kitchen' : 'Demo Merchant',
                'category_id' => $this->categories[$item[1]]->id,
                'expense_date' => today()->subDays(5)->toDateString(),
                'amount' => $item[2],
                'tax_amount' => $item[3],
            ], $items),
        ];
    }

    private function attachClaimReceipts(ExpenseClaim $claim, User $employee): void
    {
        foreach ($claim->items as $item) {
            $this->attach($item, $employee, "receipt-{$claim->claim_no}-{$item->id}.pdf");
        }
    }

    private function attach(Model $attachable, User $user, string $name): void
    {
        app(AttachmentService::class)->store($attachable, UploadedFile::fake()->create($name, 24, 'application/pdf'), $user);
    }

    private function pendingAssignment(Model $business, User $approver): ApprovalAssignment
    {
        return ApprovalAssignment::query()
            ->where('approver_id', $approver->id)
            ->where('status', ApprovalAssignmentStatus::Pending->value)
            ->whereHas('step.approvalInstance', fn ($query) => $query
                ->where('approvable_type', $business->getMorphClass())
                ->where('approvable_id', $business->getKey())
                ->where('status', ApprovalInstanceStatus::InProgress->value))
            ->latest('id')
            ->firstOrFail();
    }

    /** @return list<string> */
    private function demoEmails(): array
    {
        return ['admin@opsflow.test', 'director@opsflow.test', 'finance@opsflow.test', 'analyst@opsflow.test', 'it.manager@opsflow.test', 'employee@opsflow.test', 'operations.manager@opsflow.test', 'operations.employee@opsflow.test'];
    }

    /** @return list<string> */
    private function workflowCodes(): array
    {
        return ['PR-APPROVAL', 'INV-APPROVAL', 'EXP-APPROVAL'];
    }
}
