# TASKS

## 1. Purpose

This file defines the implementation order for OpsFlow.

Codex should work on one coherent vertical slice at a time.

Each task should:

- read only the relevant documentation
- complete the task end-to-end within its defined scope
- make reasonable Laravel-conventional implementation decisions
- avoid unrelated refactors
- run focused tests first
- fix confirmed failures before stopping
- avoid asking for confirmation unless genuinely blocked

Do not implement the entire project in one task.

Do not split routine implementation into unnecessarily small model/controller/view tasks.

---

# 2. Recommended Development Order

```text
T01 Project Shell + Authentication

T02 Master Data

T03 Shared Foundations

T04 Workflow Configuration

T05 Workflow Resolution

T06 Approval Runtime

T07 Purchase Request

T08 Supplier Invoice

T09 Expense Claim

T10 Approval Operations

T11 Resubmission + Withdrawal

T12 Audit + Notifications + Operational Views

T13 Critical Hardening

T14 Portfolio Polish
```

AI is intentionally excluded from V1 implementation.

---

# T01 — Project Shell + Authentication

## Goal

Prepare the reusable application shell and core user/authentication model.

## Read

```text
AGENTS.md
ARCHITECTURE.md
docs/architecture/authorization.md
```

## Scope

Implement:

- Laravel 13 application shell
- Blade + Bootstrap 5 + Vite
- authenticated layout
- sidebar / top navigation
- flash messages
- validation error display
- login
- logout
- forgot password
- reset password
- email verification if used
- UserRole enum
- UserStatus enum
- active/inactive user behavior
- role/status fields
- basic authentication Feature tests

Roles:

```text
ADMIN
FINANCE
EMPLOYEE
```

Statuses:

```text
ACTIVE
INACTIVE
```

## Constraints

- default role must not be ADMIN
- inactive users must not have normal application access
- role/status are not editable through ordinary profile flows
- do not add Vue, Tailwind, Redis, Elasticsearch or unnecessary dependencies

## Do Not Touch

- master data CRUD
- workflow configuration
- workflow resolution/runtime
- business modules

## Test

Run focused authentication/user tests.

## Done

Application shell works and authenticated users can access the base application.

---

# T02 — Master Data

## Goal

Implement the shared organizational and financial master data required by all modules.

## Read

```text
AGENTS.md
docs/architecture/authorization.md
docs/decisions/delete-strategy.md
```

## Scope

Implement end-to-end:

### Departments

Fields:

```text
id
name
code
manager_id nullable
status
timestamps
```

### Spend Categories

Fields:

```text
id
name
code
status
timestamps
```

### Vendors

Fields:

```text
id
name
code nullable
email nullable
phone nullable
status
timestamps
```

### User Administration

Add:

```text
department_id nullable
manager_id nullable
```

Relationships:

```text
User belongsTo Department
User belongsTo manager(User)
User hasMany directReports

Department belongsTo manager(User)
```

Admin features:

- list
- create
- update
- search
- pagination
- ACTIVE / INACTIVE
- Policies
- validation
- focused Feature tests

## Constraints

- master data should normally be deactivated, not hard-deleted
- historical users must be deactivated, not deleted
- only ADMIN manages master data and user roles
- use Laravel conventions
- no generic permission builder

## Do Not Touch

- workflow configuration
- approval runtime
- business modules

## Test

Run master-data and authorization tests.

## Done

ADMIN can maintain users, departments, categories and vendors safely.

---

# T03 — Shared Foundations

## Goal

Implement shared infrastructure required by later business modules.

## Read

```text
AGENTS.md
docs/architecture/authorization.md
docs/architecture/audit-notifications.md
docs/decisions/delete-strategy.md
docs/decisions/money-storage.md
```

## Scope

Implement:

### Reference Number Generator

Support:

```text
PR-2026-000001
INV-2026-000001
EXP-2026-000001
```

Requirements:

- server-side generation
- unique
- concurrency-safe
- deleted draft numbers are not reused

### Private Attachments

Shared polymorphic Attachment model.

Suggested fields:

```text
id
attachable_type
attachable_id
original_name
stored_name
disk
path
mime_type
size
uploaded_by
timestamps
```

Support:

- private upload
- authorized download
- delete from editable draft
- file validation

### Audit Foundation

Implement:

```text
ActivityLog
AuditService
```

Keep logging explicit and business-focused.

### Money Conventions

Establish shared decimal-safe helper/convention for:

- backend totals
- rounding
- formatting
- workflow amount comparison

## Constraints

- no public financial attachments
- no third-party money package unless clearly necessary
- never use FLOAT / DOUBLE for authoritative money
- do not create a generic logging framework

## Do Not Touch

- workflow configuration
- approval runtime
- business modules

## Test

Run focused attachment/reference/audit/money tests.

## Done

Later modules can reuse stable attachment, reference, audit and money foundations.

---

# T04 — Workflow Configuration

## Goal

Implement the complete administrative workflow configuration module.

## Read

```text
AGENTS.md
docs/architecture/workflow-overview.md
docs/architecture/workflow-configuration.md
docs/architecture/workflow-rules.md
docs/decisions/workflow-versioning.md
docs/decisions/delete-strategy.md
```

## Scope

Implement end-to-end:

```text
WorkflowTemplate
WorkflowVersion
WorkflowRuleGroup
WorkflowRule
WorkflowStep
```

Include:

- migrations
- models
- enums
- relationships
- factories if useful
- Admin Policies
- Admin CRUD
- draft workflow editing UI
- rule groups
- rules
- ordered steps
- publish validation
- publish action
- clone published version to new draft
- ActivityLog for publication
- focused Feature tests

Module types:

```text
PURCHASE_REQUEST
SUPPLIER_INVOICE
EXPENSE_CLAIM
```

Workflow version statuses:

```text
DRAFT
PUBLISHED
ARCHIVED
```

Rule fields:

```text
amount
department_id
category_id
```

Operators:

```text
=
!=
>
>=
<
<=
IN
```

Approver types:

```text
REQUESTER_MANAGER
DEPARTMENT_MANAGER
ROLE
SPECIFIC_USER
```

V1 approval mode:

```text
ANY
```

## Constraints

- only DRAFT versions are editable
- PUBLISHED versions are immutable
- one current published version per template
- at most one default rule group
- non-default groups require rules
- each rule group requires at least one step
- step order must be deterministic
- do not build drag-and-drop workflow designer

## Do Not Touch

- approval runtime
- WorkflowResolver execution
- Purchase Request
- Supplier Invoice
- Expense Claim
- notifications

## Test

Run workflow-configuration tests only first.

## Done

ADMIN can configure, validate, publish and clone workflow versions end-to-end.

---

# T05 — Workflow Resolution

## Goal

Implement deterministic workflow rule evaluation and route selection.

## Read

```text
AGENTS.md
docs/architecture/workflow-rules.md
docs/architecture/workflow-configuration.md
docs/decisions/workflow-versioning.md
docs/decisions/money-storage.md
```

## Scope

Implement:

### WorkflowContext

Normalized values:

```text
module_type
requester_id
department_id
category_id
amount
currency
```

WorkflowContext should be an explicit normalized value object / DTO.

Do not pass arbitrary Eloquent models into rule evaluation.

### RuleEvaluator

Support:

```text
=
!=
>
>=
<
<=
IN
```

Rules:

- fields are whitelisted
- field/operator combinations are validated
- amount comparisons are decimal-safe
- null required values do not match
- IN uses normalized arrays
- evaluation is deterministic

### WorkflowResolver

Selection flow:

```text
module
→ active WorkflowTemplate
→ current PUBLISHED WorkflowVersion
→ non-default RuleGroups ordered by priority ASC, id ASC
→ first matching group wins
→ default RuleGroup fallback
→ fail if no route
```

Return enough information for runtime creation:

```text
WorkflowVersion
WorkflowRuleGroup
WorkflowContext
```

A small result object may be used if it improves clarity.

## Constraints

- no runtime ApprovalInstance creation
- no approver assignment
- no AI
- no external APIs
- no custom scripts
- no nested Boolean rule engine
- rules within one group use AND
- first matching RuleGroup wins
- no silent fallback when no route/default exists
- published configuration remains immutable

## Do Not Touch

- ApprovalInstance
- ApprovalStepInstance
- ApprovalAssignment
- ApprovalAction runtime behavior
- business module CRUD
- approval inbox
- approve/reject actions
- notifications

## Test

Cover:

- every supported operator
- valid field/operator combinations
- invalid combinations
- exact amount thresholds
- decimal comparison
- AND behavior
- priority ordering
- overlapping groups
- default fallback
- no matching route
- null context values
- deterministic repeated resolution

## Done

Given the same published workflow configuration and WorkflowContext, OpsFlow always selects the same WorkflowVersion and RuleGroup or fails safely when no valid route exists.

---

# T06 — Approval Runtime

## Goal

Implement approval runtime persistence, approver resolution and workflow startup after a route has already been selected.

## Read

```text
AGENTS.md
docs/architecture/workflow-runtime.md
docs/architecture/workflow-concurrency.md
docs/architecture/authorization.md
docs/decisions/workflow-versioning.md
docs/decisions/delete-strategy.md
```

## Scope

Implement:

### Runtime Schema

```text
ApprovalInstance
ApprovalStepInstance
ApprovalAssignment
ApprovalAction
```

Use Enums for important runtime statuses and actions.

### ApprovalInstance

Store:

- approvable relationship
- selected WorkflowVersion
- selected RuleGroup
- runtime status
- current step
- timestamps
- workflow context snapshot if the schema decision is already needed here

### ApprovalStepInstance

Create runtime snapshots for the selected route.

Snapshot important configuration such as:

```text
step_order
name
approver_type
approval_mode
required_approvals
```

All selected runtime steps are created when the workflow starts.

Initial states:

```text
Step 1 → ACTIVE
Later Steps → WAITING
```

### ApproverResolver

Resolve:

```text
REQUESTER_MANAGER
DEPARTMENT_MANAGER
ROLE
SPECIFIC_USER
```

Rules:

- inactive users are not eligible
- requester cannot approve their own request
- missing required approver fails safely
- ROLE may resolve multiple eligible users
- actual users for future steps are resolved when those steps activate

### WorkflowEngine

Given an already resolved:

```text
WorkflowVersion
+
WorkflowRuleGroup
+
WorkflowContext
```

create:

- ApprovalInstance
- all ApprovalStepInstances
- first ACTIVE step
- first-step ApprovalAssignments
- initial runtime state

Do not perform business-record submission itself.

Business module services will own the outer submission transaction later.

## Constraints

- route selection belongs to WorkflowResolver, not WorkflowEngine
- selected workflow definition is frozen in runtime
- sequential V1 only
- V1 approval mode is ANY
- only first step is activated during startup
- future actual approvers are not pre-resolved unnecessarily
- no silent skipping of missing approvers
- runtime history must remain preserved
- do not hard-delete runtime records
- use consistent runtime status transitions
- keep runtime creation suitable for use inside an outer DB transaction

## Do Not Touch

- Purchase Request CRUD
- Supplier Invoice CRUD
- Expense Claim CRUD
- approval inbox UI
- approve
- reject
- request changes
- resubmission
- notifications

## Test

Cover:

- runtime schema relationships
- correct selected WorkflowVersion/RuleGroup stored
- all runtime steps created
- step snapshot preserved
- first step ACTIVE
- later steps WAITING
- REQUESTER_MANAGER resolution
- DEPARTMENT_MANAGER resolution
- ROLE resolution
- SPECIFIC_USER resolution
- inactive approver rejected
- self-approval rejected
- missing approver fails safely
- no partial runtime remains after startup failure

## Done

Given a previously resolved workflow route, OpsFlow can create a valid, historically stable approval runtime and activate its first step safely.

---

# T07 — Purchase Request Vertical Slice

## Goal

Implement Purchase Request end-to-end through submission.

## Read

```text
AGENTS.md
docs/modules/purchase-request.md
docs/architecture/authorization.md
docs/decisions/money-storage.md
docs/decisions/delete-strategy.md
```

## Scope

Implement:

```text
PurchaseRequest
PurchaseRequestItem
PurchaseRequestPolicy
PurchaseRequestService
```

Features:

- create DRAFT
- edit own DRAFT
- multiple items
- category
- optional vendor
- department derived from requester
- private attachments
- backend subtotal/tax/total calculation
- optimistic concurrency
- delete never-submitted draft
- list/search/pagination
- create/edit/detail pages
- submit into shared Workflow Engine
- approval timeline display where runtime exists
- focused Feature tests

Submit flow:

```text
lock request
→ verify DRAFT
→ recalculate totals
→ build WorkflowContext
→ resolve workflow
→ start runtime
→ PurchaseRequest = IN_APPROVAL
→ record SUBMITTED
→ commit
```

## Constraints

- requester derived server-side
- frontend totals are never authoritative
- submitted request cannot be freely edited
- no Purchase Order
- no payment
- no budget engine

## Do Not Touch

- Supplier Invoice
- Expense Claim
- approve/reject actions
- resubmission logic beyond required integration points

## Test

Cover:

- ownership
- item totals
- frontend total ignored
- draft deletion
- stale update
- valid submit
- invalid workflow
- missing approver
- double submit

## Done

Employee can create and submit a Purchase Request into approval workflow.

---

# T08 — Supplier Invoice Vertical Slice

## Goal

Implement Supplier Invoice end-to-end through submission.

## Read

```text
AGENTS.md
docs/modules/supplier-invoice.md
docs/architecture/authorization.md
docs/decisions/money-storage.md
docs/decisions/delete-strategy.md
```

## Scope

Implement:

```text
SupplierInvoice
SupplierInvoiceItem
SupplierInvoicePolicy
SupplierInvoiceService
```

Features:

- FINANCE / ADMIN draft creation
- vendor
- supplier invoice number
- internal reference
- department
- category
- invoice date
- due date
- description
- line items
- private invoice attachment
- backend totals
- optimistic concurrency
- draft deletion
- list/search/pagination
- finance invoice views
- submit into shared Workflow Engine
- focused Feature tests

Required duplicate protection:

```text
UNIQUE(vendor_id, invoice_no)
```

## Constraints

- supplier invoice attachment required before submit
- same vendor + same invoice number must not be duplicated
- same invoice number across different vendors is allowed
- approval does not mean payment
- no accounting integration
- no payment execution

## Do Not Touch

- Purchase Request
- Expense Claim
- payment module
- AI invoice extraction

## Test

Cover:

- authorization
- duplicate protection
- concurrent uniqueness protection
- attachment requirement
- backend totals
- stale update
- valid submit
- invalid workflow
- double submit

## Done

Finance can create, validate and submit Supplier Invoices into approval workflow.

---

# T09 — Expense Claim Vertical Slice

## Goal

Implement Expense Claim end-to-end through submission.

## Read

```text
AGENTS.md
docs/modules/expense-claim.md
docs/architecture/authorization.md
docs/decisions/money-storage.md
docs/decisions/delete-strategy.md
```

## Scope

Implement:

```text
ExpenseClaim
ExpenseItem
ExpenseClaimPolicy
ExpenseClaimService
```

Features:

- employee-owned DRAFT
- title / description
- multiple Expense Items
- item category
- expense date
- merchant
- amount
- tax amount
- item-level receipt attachments
- receipt validation
- backend claim total
- deterministic routing category
- optimistic concurrency
- draft deletion
- list/search/pagination
- create/edit/detail UI
- Finance visibility
- submit into shared Workflow Engine
- focused Feature tests

Routing category V1:

```text
category of highest-value ExpenseItem
```

Tie:

```text
earliest stable item
```

## Constraints

- employee derived server-side
- department derived from employee
- expense date cannot be future in V1
- frontend claim total ignored
- tax is breakdown of gross amount, not additional claim total
- missing required receipt blocks submission
- no reimbursement execution

## Do Not Touch

- AI receipt extraction
- payroll
- mileage engine
- corporate cards

## Test

Cover:

- ownership
- expense date
- total calculation
- tax behavior
- receipt requirement
- routing category
- stale update
- valid submit
- invalid workflow
- double submit

## Done

Employee can create and submit Expense Claims into approval workflow.

---

# T10 — Approval Operations

## Goal

Implement the operational approval experience end-to-end.

## Read

```text
AGENTS.md
docs/architecture/workflow-runtime.md
docs/architecture/workflow-concurrency.md
docs/architecture/authorization.md
docs/architecture/audit-notifications.md
```

## Scope

Implement:

### Approval Inbox

Query persisted ApprovalAssignments:

```text
approver_id = current user
status = PENDING
```

Show:

- business reference
- module
- requester
- amount
- current step
- assigned date

### Approval Detail

Show:

- business data
- items
- attachments
- approval timeline
- current step
- available actions

### ApprovalService

Implement:

```text
approve
reject
requestChanges
```

Approve:

- lock authoritative runtime state
- re-check assignment/step/instance/business state
- record APPROVED
- complete current step
- activate next step
- resolve next approver
- complete workflow on final step

Reject:

```text
assignment → REJECTED
step → REJECTED
instance → REJECTED
business record → REJECTED
future steps → CANCELLED
```

Request Changes:

```text
business record → CHANGES_REQUESTED
current assignment becomes non-actionable
ApprovalAction → CHANGES_REQUESTED
```

Require meaningful comment for reject/request changes.

## Constraints

- only active assigned approver may act
- server-side checks are authoritative
- sequential workflow only
- V1 ANY mode
- first committed valid action wins
- double actions must not advance runtime twice
- approval state changes only through ApprovalService

## Do Not Touch

- resubmission implementation
- notifications beyond events/hooks if required
- AI approval summaries

## Test

Cover:

- assigned approver succeeds
- unassigned user fails
- waiting step cannot act
- self-approval protection
- double approve
- ANY-step race invariants
- approve vs reject
- request changes
- final approval
- rollback safety

## Done

Approvers can safely process requests from Inbox through completion/rejection/change request.

---

# T11 — Resubmission + Withdrawal

## Goal

Implement the complete correction/resubmission lifecycle.

## Read

```text
AGENTS.md
docs/architecture/workflow-resubmission.md
docs/architecture/workflow-concurrency.md
docs/decisions/workflow-versioning.md
```

## Scope

Implement:

### Workflow Context Snapshot

Store normalized routing context on ApprovalInstance.

Example:

```json
{
  "amount": "1000.00",
  "department_id": 2,
  "category_id": 7
}
```

### Non-Material Resubmission

```text
same route
→ same ApprovalInstance
→ same current step
→ fresh ApprovalAssignment
→ previous completed steps preserved
```

### Material Resubmission

```text
routing-relevant data changed
→ resolve current workflow again
```

If effective Version / Rule Group changes:

```text
validate new route
→ old ApprovalInstance CANCELLED
→ create new ApprovalInstance
→ restart from Step 1
```

### Withdrawal

Allowed from:

```text
IN_APPROVAL
CHANGES_REQUESTED
```

Result:

```text
business record → WITHDRAWN
ApprovalInstance → CANCELLED
pending runtime → non-actionable
```

Support all three modules.

## Constraints

- REJECTED cannot resubmit in V1
- WITHDRAWN cannot resubmit in V1
- non-material edits stay on original workflow version
- material new route may use current published version
- old runtime is preserved
- never cancel old runtime before validating replacement runtime
- operations are transactional and concurrency-safe

## Do Not Touch

- delegation
- SLA
- parallel approvals
- reopening rejected records

## Test

Cover:

- attachment-only resubmit
- same route after amount edit
- changed route restart
- new published version behavior
- duplicate resubmit
- invalid new route
- withdrawal
- withdraw vs approve race

## Done

All three business modules safely support Request Changes → edit → resubmit and withdrawal.

---

# T12 — Audit + Notifications + Operational Views

## Goal

Complete operational usability after core workflow behavior is stable.

## Read

```text
AGENTS.md
docs/architecture/audit-notifications.md
docs/architecture/authorization.md
```

## Scope

Implement:

### Approval Timeline

Reusable display based on ApprovalAction.

Show:

- action
- actor
- step
- timestamp
- comment

### Activity Logs

Add explicit logs for important actions:

- workflow publication
- vendor changes
- department changes
- category changes
- role changes
- important business edits

### Database Notifications

Implement:

```text
ApprovalAssigned
ChangesRequested
RequestApproved
RequestRejected
RequestResubmitted
```

Notifications happen after authoritative transaction commit.

### Notification Center

Implement:

- unread count
- list
- mark read
- secure target links

### Operational Dashboards

Employee:

```text
My Drafts
Waiting Approval
Changes Requested
Recently Approved
```

Approver:

```text
My Approval Inbox
Pending Count
Oldest Pending
```

Finance:

```text
Supplier Invoices Awaiting Approval
Expense Claims Requiring Finance Review
Approved Supplier Invoices
Due Soon
```

Admin:

```text
Active Users
Active Workflows
Requests by Status
Recent Workflow Publications
Blocked Workflows
```

## Constraints

- notification read status does not affect workflow state
- notification failure does not roll back valid business state
- avoid decorative analytics
- no SLA/escalation unless separately implemented
- email notification is optional after database notification is stable

## Do Not Touch

- AI features
- payment
- reimbursement
- advanced analytics

## Test

Run focused notification/audit/dashboard authorization tests.

## Done

Users can understand what happened, what needs attention and what action comes next.

---

# T13 — Critical Hardening

## Goal

Review and harden the completed V1 against the documented architectural invariants.

## Read

```text
AGENTS.md
ARCHITECTURE.md
docs/architecture/workflow-concurrency.md
docs/architecture/authorization.md
docs/decisions/money-storage.md
docs/decisions/delete-strategy.md
```

## Scope

Review and fix only confirmed issues in:

### Authorization

- ownership
- ADMIN boundaries
- FINANCE boundaries
- runtime assignment
- private attachments
- inactive users
- self-approval

### Workflow Rules

- operators
- priority
- default
- overlap
- deterministic results
- exact amount boundaries

### Runtime

- submit
- approve
- reject
- request changes
- resubmit
- withdraw
- final approval
- blocked workflow

### Concurrency

- double submit
- double approve
- ANY approval races
- double resubmit
- withdraw vs approve
- delete vs submit
- stale edits

### Transactions

Force failures and verify no partial state remains.

### Money

Verify:

- backend authority
- decimal handling
- tax conventions
- threshold boundaries
- frontend totals ignored

### Delete Strategy

Verify:

- submitted records cannot be hard-deleted
- master data deactivated rather than deleted
- historical runtime remains
- published workflow versions remain

## Constraints

- do not redesign working architecture without a confirmed issue
- do not perform unrelated cleanup
- prioritize correctness over cosmetic refactoring

## Test

Run focused tests first, then relevant module tests, then full suite at the end of this task.

## Done

Critical architectural invariants are covered by tests and confirmed behavior.

---

# T14 — Portfolio Polish

## Goal

Prepare OpsFlow for GitHub, demo and interview use.

## Read

```text
AGENTS.md
ARCHITECTURE.md
TASKS.md
```

## Scope

Implement:

### Demo Seed Data

Create realistic:

- users
- managers
- departments
- vendors
- categories
- workflow templates
- workflow versions
- sample requests

### Demo Workflows

Purchase Request:

```text
IT High Value
Standard High Value
Default
```

Supplier Invoice:

```text
Department Manager
Finance
Director for High Value
```

Expense Claim:

```text
Requester Manager
Finance
```

### README

Explain:

- project purpose
- business problem
- architecture
- workflow engine
- workflow versioning
- authorization
- money correctness
- concurrency
- screenshots
- setup
- test commands
- future AI roadmap

### UI Polish

Review:

- navigation
- empty states
- validation feedback
- status badges
- action placement
- approval detail usability
- responsive Bootstrap behavior

### Final Code Review

Check:

- thin controllers
- services own workflows
- no duplicated transitions
- no frontend-authoritative totals
- no public private attachments
- no accidental hard deletes
- no obvious N+1
- no unused dependencies
- no debug code
- no secrets
- consistent enums

## Constraints

- do not add new major business features
- do not introduce AI yet
- do not refactor for abstraction alone

## Test

Run full test suite.

## Done

Repository is clear, stable, demonstrable and easy to explain in an interview.

---

# 3. Deferred After V1

Do not allow these features to expand V1 scope:

```text
AI document extraction
AI receipt extraction
AI approval summaries
AI anomaly detection
AI assistant

Parallel approvals
ALL / MINIMUM approval modes
Delegation
SLA / escalation

Payment execution
Reimbursement execution
Purchase Orders
Budget management
Accounting integration
AutoCount integration
Corporate cards

Multi-company
FX conversion
Advanced tax engine
Mobile application
Microservices
```

---

# 4. Suggested AI Phase After V1

Only after V1 is stable:

```text
AI-01 Supplier Invoice Extraction
AI-02 Expense Receipt Extraction
AI-03 Category Suggestions
AI-04 Approval Summary
AI-05 Duplicate / Anomaly Hints
AI-06 Finance / Policy Assistant
```

AI remains advisory.

Deterministic business rules and humans remain authoritative.

---

# 5. Codex Execution Style

Use Codex primarily in two modes.

## Build Mode

Use for:

- CRUD
- migrations
- models
- Forms / Blade UI
- normal Policies
- standard Feature tests
- complete module vertical slices

Instruction style:

```text
Implement the task end-to-end.
Make reasonable Laravel-conventional decisions.
Do not stop for confirmation unless genuinely blocked.
Run focused tests and fix failures.
```

## Review Mode

Use separately for:

- concurrency
- resubmission
- workflow versioning
- authorization boundaries
- money correctness
- transaction rollback

Review should focus on documented invariants and confirmed issues.

Do not use review-level caution for every routine CRUD implementation.

---

# 6. Standard Codex Prompt Format

Use:

```text
Goal:

Read:

Scope:

Do not touch:

Constraints:

Test:

Done:
```

Example:

```text
Goal:
Implement the Workflow Configuration module end-to-end.

Read:
AGENTS.md
docs/architecture/workflow-configuration.md
docs/architecture/workflow-rules.md
docs/decisions/workflow-versioning.md

Scope:
- migrations/models/enums
- Admin CRUD
- rule groups/rules/steps
- draft editing
- publish validation
- clone version
- focused Feature tests

Do not touch:
- approval runtime
- Purchase Request
- Supplier Invoice
- Expense Claim
- notifications

Constraints:
- published versions immutable
- first matching rule group wins by priority
- no new dependencies
- follow Laravel conventions

Test:
Run focused workflow configuration tests and fix failures.

Done:
Workflow Configuration works end-to-end and relevant tests pass.
```

---

# 7. Context Discipline

Do not tell Codex to read the whole repository or all documentation by default.

Examples:

### Workflow Configuration

```text
AGENTS.md
workflow-configuration.md
workflow-rules.md
workflow-versioning.md
```

### Workflow Resolution

```text
AGENTS.md
workflow-rules.md
workflow-configuration.md
workflow-versioning.md
money-storage.md
```

### Approval Runtime

```text
AGENTS.md
workflow-runtime.md
workflow-concurrency.md
authorization.md
workflow-versioning.md
```

### Purchase Request

```text
AGENTS.md
purchase-request.md
money-storage.md
delete-strategy.md
authorization.md
```

### Approval Operations

```text
AGENTS.md
workflow-runtime.md
workflow-concurrency.md
authorization.md
audit-notifications.md
```

Load additional documentation only when needed.

---

# 8. Testing Discipline

For each build task:

```text
1. implement the coherent slice
2. run focused tests
3. fix failures
4. run related module tests
5. stop when acceptance criteria are met
```

Run the full suite at meaningful checkpoints, especially:

```text
after Workflow Resolution + Approval Runtime
after Approval Operations
after Resubmission
during Critical Hardening
before final completion
```

Do not run the full suite after every trivial change.

---

# 9. Commit Discipline

Prefer:

```text
one coherent vertical slice
≈
one coherent commit
```

Examples:

```text
feat: add master data administration

feat: implement workflow configuration

feat: implement workflow resolution

feat: implement approval runtime

feat: add purchase request workflow

feat: add supplier invoice workflow

feat: add expense claim workflow

feat: implement approval operations

feat: support approval resubmission

feat: add audit and notifications

test: harden workflow concurrency
```

Do not combine unrelated modules into one commit.

---

# 10. V1 Completion Definition

OpsFlow V1 is complete when these flows work end-to-end.

## Purchase Request

```text
Employee
→ Create Draft
→ Add Items
→ Submit
→ Approval
→ Approved
```

## Supplier Invoice

```text
Finance
→ Create Invoice
→ Duplicate Validation
→ Submit
→ Approval
→ Approved
```

## Expense Claim

```text
Employee
→ Add Expenses + Receipts
→ Submit
→ Approval
→ Approved
```

All three must support:

```text
Request Changes
Resubmit
Reject
Withdraw
Approval Timeline
Authorization
Private Attachments
Audit History
Notifications
```

Critical behavior must be tested for:

```text
transactions
locking
workflow versioning
money correctness
authorization
historical preservation
```

---

# 11. Final Principle

Implementation priority:

```text
Correct Business Flow
        ↓
Authorization
        ↓
Data Integrity
        ↓
Concurrency Safety
        ↓
Focused Tests
        ↓
Operational UX
        ↓
Portfolio Polish
        ↓
AI
```

Do not optimize OpsFlow for task count or feature count.

Optimize it for coherent vertical slices that Codex can implement efficiently and that remain easy to review and explain.