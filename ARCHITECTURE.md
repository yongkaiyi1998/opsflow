# OpsFlow Architecture

## 1. Overview

OpsFlow is a Spend Management & Approval Workflow Platform built with Laravel.

The platform supports three primary business processes:

- Purchase Requests
- Supplier Invoices
- Expense Claims

These modules share a reusable Approval Workflow Engine that determines:

- which workflow applies
- who must approve
- in what order approvals occur
- how requests are rejected or returned for changes
- how approval history is preserved

The core system must remain fully functional without AI.

AI features are optional enhancements planned for a later phase.

---

# 2. Goals

OpsFlow is designed to:

- reduce manual approval coordination
- automatically route requests to the correct approvers
- make approval ownership visible
- reduce repeated email or chat follow-up
- preserve complete approval and audit history
- support configurable approval workflows
- prevent invalid or duplicate state transitions
- remain easy to understand and maintain
- provide a clean foundation for future AI-assisted features

The architecture prioritizes:

- correctness
- clarity
- auditability
- maintainability
- testability
- incremental development

---

# 3. Non-Goals

The initial system is not intended to be:

- a full ERP
- an accounting system
- a payment gateway
- a procurement suite
- a corporate card platform
- a payroll system
- a multi-company SaaS platform
- a microservices system
- an AI-driven autonomous approval system

The initial architecture should not be complicated by these future possibilities.

---

# 4. Technology Stack

The application uses:

- Laravel 13
- PHP 8.3+
- MySQL
- Blade
- Bootstrap 5
- Vite
- PHPUnit / Laravel Feature Tests
- Laravel Queue
- Laravel Scheduler
- Laravel Notifications
- Laravel Events

The application is a Laravel monolith.

A monolith is preferred because the business domain is strongly connected and does not currently justify distributed services.

---

# 5. High-Level Architecture

```text
Browser
   ↓
Routes
   ↓
Middleware
   ↓
Form Requests
   ↓
Controllers
   ↓
Application / Domain Services
   ↓
Eloquent Models
   ↓
MySQL
```

Cross-cutting components include:

```text
Policies
Events
Listeners
Jobs
Notifications
Scheduler
Audit Logging
File Storage
```

Future optional AI components sit outside the authoritative business workflow.

---

# 6. Application Layer Responsibilities

## Controllers

Controllers are responsible for HTTP concerns.

Typical responsibilities:

- receive requests
- authorize actions
- use validated data
- call the appropriate service
- return views, redirects, or responses

Controllers should not contain complex workflow logic.

---

## Form Requests

Form Requests handle server-side input validation.

Examples include:

- PurchaseRequestRequest
- SupplierInvoiceRequest
- ExpenseClaimRequest
- WorkflowRequest

Validation must not be delegated to frontend code.

---

## Policies

Policies provide resource-level authorization.

They determine whether a user may:

- view a record
- create a record
- update a draft
- submit a request
- approve an assigned request
- manage workflow configuration

Frontend visibility does not replace server-side authorization.

---

## Services

Services implement business workflows.

Expected services include:

```text
PurchaseRequestService
SupplierInvoiceService
ExpenseClaimService

WorkflowResolver
WorkflowEngine
ApprovalService

DelegationService
AuditService
```

Services may coordinate multiple models inside database transactions.

---

# 7. Core Business Domains

The system contains three primary spend domains.

---

## Purchase Request

Represents a request to spend company money before a purchase occurs.

Examples:

- laptops
- software subscriptions
- professional services
- office equipment

A Purchase Request may contain multiple line items.

The backend calculates authoritative totals.

---

## Supplier Invoice

Represents an invoice received from an external vendor.

Examples:

- rental invoice
- cloud service invoice
- supplier service invoice
- equipment invoice

Supplier invoices should support duplicate protection using vendor and supplier invoice number where appropriate.

---

## Expense Claim

Represents expenses already paid personally by an employee and submitted for reimbursement.

A claim may contain multiple Expense Items.

Examples:

- transport
- hotel
- meals
- parking
- business purchases

The claim total is calculated from its items.

---

# 8. Shared Master Data

The three spend domains may reference shared master data such as:

- Users
- Departments
- Spend Categories
- Vendors

Master data should generally use lifecycle status such as ACTIVE or INACTIVE instead of destructive deletion when historical records may reference it.

---

# 9. Organization Model

Users belong to departments.

Users may also have a direct manager.

Conceptually:

```text
Department
   ├── Department Manager
   └── Users

User
   └── Direct Manager
```

Department Manager and Direct Manager are separate concepts.

This allows workflow steps to resolve approvers using organizational relationships rather than hard-coded user IDs.

---

# 10. Approval Workflow Architecture

The Approval Workflow Engine is the core reusable subsystem.

It is divided into two distinct areas:

```text
Workflow Configuration
        ↓
Workflow Runtime
```

These must remain separate.

---

# 11. Workflow Configuration

Workflow configuration defines how future approval processes should behave.

Main concepts:

```text
Workflow Template
      ↓
Workflow Version
      ↓
Rule Groups / Rules
      ↓
Workflow Steps
```

---

## Workflow Template

A Workflow Template represents a logical approval process.

Examples:

```text
Purchase Request Approval
Supplier Invoice Approval
Expense Claim Approval
```

A template may have multiple versions over time.

---

## Workflow Version

Workflow Versions allow approval rules to evolve safely.

Example:

Version 1:

```text
Amount > RM10,000
Manager → Director
```

Version 2:

```text
Amount > RM10,000
Manager → Finance → Director
```

An existing request submitted using Version 1 must continue using Version 1.

Publishing Version 2 must not silently modify historical or in-progress approvals.

---

# 12. Workflow Versioning Principle

When a request is submitted:

```text
Request
   ↓
WorkflowResolver
   ↓
Matching Workflow Version
   ↓
Approval Runtime
```

The selected Workflow Version becomes part of the approval instance.

Existing approval instances remain attached to that version.

Workflow configuration is therefore effectively immutable from the perspective of existing approval instances.

---

# 13. Workflow Conditions

V1 uses intentionally simple conditions.

Supported fields may include:

```text
amount
department
category
```

Supported operators may include:

```text
=
!=
>
>=
<
<=
IN
```

Example:

```text
amount > 10000
AND
department = IT
```

Rule groups should remain understandable to non-technical administrators.

The architecture intentionally avoids a general-purpose scripting or deeply nested Boolean expression engine in V1.

---

# 14. Workflow Resolution

WorkflowResolver is responsible for selecting the correct workflow configuration.

Conceptually:

```text
Request Data
    ↓
Find Published Workflow Versions
    ↓
Evaluate Rule Groups
    ↓
Choose Matching Version
```

WorkflowResolver does not execute approvals.

Its responsibility ends after resolving the appropriate workflow version.

---

# 15. Workflow Steps

A Workflow Version contains ordered approval steps.

Example:

```text
Step 1: Requester Manager
Step 2: Finance
Step 3: Specific Director
```

Supported approver resolution types may include:

```text
REQUESTER_MANAGER
DEPARTMENT_MANAGER
ROLE
SPECIFIC_USER
```

Approval routing must be configuration-driven.

Business services should not contain hard-coded approver names or IDs.

---

# 16. Approval Runtime

Workflow configuration describes what should happen.

Approval Runtime records what actually happened for a specific request.

Runtime concepts include:

```text
ApprovalInstance
      ↓
ApprovalStepInstance
      ↓
ApprovalAssignment
      ↓
ApprovalAction
```

---

# 17. Approval Instance

An Approval Instance represents one complete approval process for one business record.

It references:

- the approvable record
- the selected Workflow Version
- current runtime status
- current step
- started/completed timestamps

The approvable record may be:

- Purchase Request
- Supplier Invoice
- Expense Claim

A Laravel polymorphic relationship is suitable here.

---

# 18. Approval Step Instance

Each configured Workflow Step is represented at runtime by an Approval Step Instance.

The runtime record preserves important execution information such as:

- step order
- step name
- approval mode
- status
- start time
- completion time

This prevents historical approval execution from depending entirely on editable configuration.

---

# 19. Approval Assignment

An Approval Assignment represents a specific user who is expected to act.

Example:

```text
Finance Review
    ↓
Mary
    ↓
PENDING
```

Assignments allow the system to build:

```text
My Approval Inbox
```

without recalculating ownership every time the page loads.

---

# 20. Approval Action

Approval Actions provide an append-only approval history.

Examples:

```text
SUBMITTED
APPROVED
REJECTED
CHANGES_REQUESTED
RESUBMITTED
WITHDRAWN
DELEGATED
```

An Approval Action should record:

- actor
- action
- related approval instance
- related step
- comment
- timestamp
- optional metadata

Approval history should not be erased when the workflow progresses.

---

# 21. Approval State Model

Typical request lifecycle:

```text
DRAFT
   ↓ submit

SUBMITTED
   ↓

IN_APPROVAL
   ├── approve ───────────→ APPROVED
   │
   ├── reject ────────────→ REJECTED
   │
   └── request changes ───→ CHANGES_REQUESTED
                                 ↓
                              resubmit
                                 ↓
                            IN_APPROVAL
```

State transitions are explicit business operations.

Controllers must not directly mutate these states.

---

# 22. Reject vs Request Changes

REJECTED and CHANGES_REQUESTED have different meanings.

## REJECTED

The current request is not approved.

It does not continue through the existing approval workflow.

---

## CHANGES_REQUESTED

The requester is allowed to correct or provide additional information.

After correction, the request may be resubmitted.

Completed approval history is preserved.

---

# 23. Resubmission Strategy

For V1, when an approver requests changes:

```text
Manager ✓
Finance → Changes Requested
```

after resubmission:

```text
Manager remains completed
Finance resumes
```

The request does not restart from the first step unless a future business rule explicitly requires it.

This reduces unnecessary repeated approval work.

---

# 24. ApprovalService

ApprovalService owns approval state transitions.

Expected operations include:

```text
approve()
reject()
requestChanges()
resubmit()
withdraw()
```

ApprovalService is responsible for:

- authorization assumptions already verified by caller/policy
- validating current state
- locking relevant records
- recording actions
- updating assignments
- completing steps
- activating the next step
- completing the approval instance
- updating the business record

These operations should be transactional.

---

# 25. Transaction Boundaries

Important business workflows must be atomic.

Examples include:

## Submit

```text
Validate
↓
Resolve Workflow
↓
Create Approval Instance
↓
Create Runtime Steps
↓
Assign First Approver
↓
Update Business Record
↓
Commit
```

## Approve

```text
Lock Assignment
↓
Confirm Still Pending
↓
Record Approval Action
↓
Complete Assignment
↓
Determine Step Completion
↓
Activate Next Step
or
Complete Approval
↓
Commit
```

Equivalent transaction boundaries apply to reject and request-changes operations.

---

# 26. Concurrency

Approval actions are concurrency-sensitive.

The same assignment may be submitted twice because of:

- user double-click
- browser retry
- repeated HTTP request
- multiple open tabs

ApprovalService must use database locking where necessary.

Conceptually:

```text
Begin Transaction
↓
SELECT assignment FOR UPDATE
↓
Verify status is still PENDING
↓
Apply transition
↓
Commit
```

Only one operation should succeed in advancing a single assignment.

---

# 27. Idempotency

Operations that may be retried should not corrupt system state.

The application must not assume:

```text
one request = exactly one execution
```

This principle applies to:

- approval actions
- scheduled processes
- jobs
- notifications
- future external integrations

Database constraints and state checks should be used wherever appropriate.

---

# 28. Financial Data

All authoritative monetary calculations happen on the server.

Frontend-supplied totals are never trusted.

Use fixed decimal database values such as:

```text
DECIMAL(15,2)
```

Do not use floating-point database types for money.

Examples of backend-calculated values:

- item subtotal
- tax
- request subtotal
- request total
- expense claim total

---

# 29. Attachments

Business records may have private attachments.

Examples:

- quotations
- receipts
- supplier invoices
- supporting documents

Attachments use Laravel Storage abstractions.

Files should not be exposed as unrestricted public URLs.

Download and preview requests must pass authorization checks.

The storage implementation should be replaceable later, for example from local private storage to private S3 storage.

---

# 30. Audit Architecture

OpsFlow contains two related audit concepts.

## Approval History

Handled through ApprovalAction.

Used for:

- approve
- reject
- request changes
- resubmit
- delegation

## General Activity Log

Used for broader administrative and business changes.

Examples:

- workflow published
- vendor updated
- request edited
- delegation created
- configuration changed

Important historical information should be append-oriented rather than silently overwritten.

---

# 31. Events and Side Effects

Core state transitions should remain separate from secondary side effects.

Example:

```text
ApprovalService
    ↓
Database Commit
    ↓
RequestApproved Event
    ↓
Listeners
    ├── Notification
    ├── Email
    └── Audit / Other Side Effect
```

Notification failure must not roll back an otherwise valid approval.

When events, listeners, or jobs depend on committed data, they should run after the relevant transaction commits.

---

# 32. Notifications

V1 may support:

- database notifications
- email notifications

Important events include:

```text
RequestSubmitted
ApprovalAssigned
RequestApproved
RequestRejected
ChangesRequested
RequestResubmitted
ApprovalOverdue
```

Notifications should point users toward the exact action or request that requires attention.

---

# 33. Scheduler

Laravel Scheduler may support recurring processes such as:

- overdue approval detection
- SLA reminders
- escalation
- stale draft cleanup

Scheduled operations must be safe to execute repeatedly.

A scheduled command should not create duplicate business actions simply because it runs twice.

---

# 34. Delegation

Future approval delegation supports temporary approver replacement.

Example:

```text
John
Sep 10–20
    ↓
Mary handles John's approvals
```

Delegation affects assignment resolution.

It does not modify the published Workflow Version.

This keeps workflow configuration independent from temporary staff availability.

---

# 35. Authorization Model

Authorization uses a combination of:

```text
Role
+
Organization Relationship
+
Policy
+
Approval Assignment
```

Roles provide broad access.

Examples:

```text
ADMIN
FINANCE
EMPLOYEE
```

Manager is primarily an organizational relationship rather than necessarily a dedicated global role.

A user may approve a request only when the runtime approval system assigns the relevant responsibility or another documented rule grants access.

---

# 36. Delete Strategy

Business and audit records are historical records.

The following should not be hard-deleted after entering business workflow:

- submitted Purchase Requests
- Supplier Invoices
- Expense Claims
- Approval Instances
- Approval Step Instances
- Approval Assignments
- Approval Actions
- Activity Logs

Master data generally uses lifecycle states such as:

```text
ACTIVE
INACTIVE
```

Historical references must remain valid.

---

# 37. Query and Performance Strategy

The application should use:

- pagination
- eager loading
- appropriate indexes
- bounded queries

Important performance paths include:

```text
My Approval Inbox
My Requests
Finance Workspace
Workflow Configuration
```

Approval Inbox should normally query runtime Approval Assignments directly rather than dynamically rebuilding the workflow for every request.

---

# 38. Dashboard Architecture

Different users require different operational views.

## Employee

Examples:

```text
My Drafts
Waiting Approval
Changes Requested
Recently Approved
```

## Approver

Examples:

```text
My Approval Inbox
Due Today
Overdue Approvals
```

## Finance

Examples:

```text
Awaiting Finance Review
Supplier Invoices
Expense Claims
Upcoming Due Dates
```

## Admin

Examples:

```text
Workflow Bottlenecks
Approval Duration
Requests by Department
Requests by Category
```

Dashboards should focus on actions and bottlenecks, not only decorative charts.

---

# 39. AI Extension Architecture

AI is an optional enhancement layer.

Potential future components include:

```text
DocumentExtractionService
SpendClassificationService
ApprovalSummaryService
AnomalyAnalysisService
SpendAssistant
```

AI does not become the source of truth for business decisions.

---

# 40. AI Responsibility Boundary

The architectural principle is:

```text
Business rules decide.
AI assists.
Humans approve.
```

Examples:

AI may:

- extract invoice fields
- summarize requests
- suggest categories
- highlight possible anomalies
- explain workflow rules in natural language

AI should not:

- directly approve requests
- silently alter financial values
- override workflow routing
- bypass authorization
- make irreversible authoritative decisions

---

# 41. AI Failure Isolation

The core spend and approval system must continue to operate if an AI provider is unavailable.

AI-related work should therefore be designed so that:

- failures are retryable where appropriate
- output is validated
- automated tests can use fake responses
- AI failure does not corrupt business state
- core approval workflows remain usable without AI

---

# 42. Database Design Principles

Database design should enforce important invariants.

Use:

- foreign keys
- unique constraints
- composite unique constraints
- indexes

where appropriate.

Examples:

```text
vendor + invoice number
request number
workflow version number
```

Application validation does not replace database-level correctness guarantees.

---

# 43. Naming and Identifiers

Business records should use human-readable reference numbers.

Examples:

```text
PR-2026-000001
INV-2026-000001
EXP-2026-000001
```

Internal database IDs remain separate from business identifiers.

Reference number generation must be safe under concurrent creation.

---

# 44. Testing Architecture

Automated tests should emphasize business behavior rather than only CRUD success.

Important test areas include:

```text
Authorization

Financial calculations

Workflow resolution

Workflow versioning

Submission

Approval assignment

Approval transitions

Rejection

Request changes

Resubmission

Duplicate action protection

Concurrency

Audit history
```

Feature Tests are preferred for business workflows.

Unit tests may be used for isolated rule evaluation or calculation logic where useful.

---

# 45. Critical Workflow Tests

The following scenarios are considered high-value architecture tests.

## Workflow Versioning

```text
Request A submits under V1

Admin publishes V2

Request A remains on V1

Request B submits under V2
```

## Duplicate Approval

```text
Same approval action submitted twice

Only first action changes state
```

## Resubmission

```text
Manager approves

Finance requests changes

Requester edits and resubmits

Manager approval remains completed

Finance resumes
```

## Authorization

```text
Employee cannot approve an unassigned request
```

---

# 46. Initial Module Boundaries

The project is divided into the following logical modules:

```text
Identity & Organization

Vendor & Spend Category

Purchase Request

Supplier Invoice

Expense Claim

Workflow Configuration

Workflow Resolution

Approval Runtime

Approval Inbox

Notifications

Audit

Reporting
```

Modules may share infrastructure but should avoid unnecessary coupling.

---

# 47. V1 Scope

V1 includes:

```text
Authentication

Users
Departments
Spend Categories
Vendors

Purchase Requests
Supplier Invoices
Expense Claims

Private Attachments

Workflow Templates
Workflow Versions
Simple Workflow Conditions
Sequential Workflow Steps

Approval Runtime
Approval Inbox

Approve
Reject
Request Changes
Resubmit
Withdraw

Audit Trail

Database Notifications

Feature Tests
```

---

# 48. Deferred Features

The following are intentionally deferred:

```text
AI

Parallel approvals

Advanced ANY / ALL / minimum approvals

Delegation

SLA escalation

Complex Boolean rule builder

Budgets

Purchase Orders

Payments

Accounting integrations

Corporate cards

Multi-company

Currency conversion

Mobile applications

Microservices
```

The architecture should allow these features later without requiring them now.

---

# 49. Development Philosophy

OpsFlow should prefer the simplest architecture that safely models the real business problem.

Avoid abstractions that exist only to make the architecture appear more sophisticated.

The desired system is:

```text
Easy to understand
Easy to test
Hard to misuse
Auditable
Safe under concurrency
Flexible where business rules actually change
Simple where they do not
```

---

# 50. Architectural Summary

The central architecture can be summarized as:

```text
Purchase Request
Supplier Invoice
Expense Claim
        ↓
WorkflowResolver
        ↓
Published Workflow Version
        ↓
WorkflowEngine
        ↓
Approval Runtime
        ↓
Approval Assignments
        ↓
ApprovalService
        ↓
Approve / Reject / Request Changes
        ↓
Audit + Notifications
```

Workflow configuration defines future behavior.

Approval runtime preserves what actually happened.

Business Services control authoritative state transitions.

Database transactions and locking protect correctness.

Policies protect access.

AI remains an optional assistance layer.

This separation is the foundation of OpsFlow.