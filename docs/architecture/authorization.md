# Authorization

## 1. Purpose

Authorization determines what each user is allowed to see and do inside OpsFlow.

The system must protect:

- business records
- approval actions
- workflow configuration
- private attachments
- administrative settings
- finance-only data

Authorization must always be enforced server-side.

Frontend visibility is only a usability feature.

---

# 2. Authorization Model

OpsFlow uses a combination of:

```text id="c0q8o3"
Role
+
Organization Relationship
+
Resource Ownership
+
Approval Assignment
+
Laravel Policy
```

No single mechanism is enough by itself.

---

# 3. Roles

V1 uses a small set of broad roles.

Recommended:

```text id="ao7rza"
ADMIN
FINANCE
EMPLOYEE
```

Do not create a separate global role for every business relationship.

For example:

```text id="f1t0ya"
MANAGER
```

does not need to be a global role.

A manager is primarily determined through organization relationships.

---

# 4. Why Manager Is Not a Global Role

A user may be:

```text id="j48r4d"
Employee
+
Manager of 5 users
+
Requester
+
Approver
```

These are contextual responsibilities.

Using only:

```text id="de6djm"
role = MANAGER
```

cannot answer:

> Manager of whom?

Therefore manager access should be based on relationships such as:

```text id="f3xzdk"
users.manager_id
```

and runtime ApprovalAssignments.

---

# 5. ADMIN

ADMIN has system administration responsibilities.

Typical permissions:

- manage users
- manage departments
- manage spend categories
- manage vendors
- manage workflow templates
- create workflow versions
- publish workflows
- manage system configuration
- view administrative audit logs

ADMIN does not automatically need to approve every business request.

Approval responsibility should still come from workflow assignment unless a specific administrative override feature is introduced later.

---

# 6. FINANCE

FINANCE represents users responsible for finance-related operational work.

Typical permissions:

- view Supplier Invoices within finance scope
- view Expense Claims requiring finance review
- receive role-based finance approval assignments
- access Finance Workspace
- access finance reports
- review duplicate invoice warnings
- view relevant supporting documents

FINANCE should not automatically gain permission to edit arbitrary requester-owned business data.

---

# 7. EMPLOYEE

EMPLOYEE is the standard application user.

Typical permissions:

- create Purchase Requests
- create Expense Claims
- view own requests
- edit own drafts
- edit own requests when changes are requested
- submit eligible requests
- withdraw eligible own requests
- view approval timeline for own requests

An employee cannot approve a request merely because they can view it.

---

# 8. Requester Ownership

Each requester-owned record should have a clear owner.

Examples:

```text id="uxdgb2"
PurchaseRequest.requester_id
```

```text id="qtpwmh"
ExpenseClaim.employee_id
```

Supplier Invoice may use:

```text id="ox5yvc"
submitted_by
```

Ownership is used for operations such as:

- view own request
- edit draft
- resubmit
- withdraw

---

# 9. Department Relationships

Business records may belong to a department.

Example:

```text id="4y11w7"
department_id
```

Department relationship can affect:

- workflow routing
- reporting
- manager resolution
- visibility where explicitly allowed

Department membership alone should not automatically grant broad read access to every record in the department unless a documented requirement says so.

---

# 10. Direct Manager Relationship

A user may have:

```text id="2b72an"
manager_id
```

This relationship supports workflow resolution such as:

```text id="dv0f11"
REQUESTER_MANAGER
```

Being someone's manager does not automatically mean the manager can edit that person's request.

It normally grants approval/view access only when the workflow runtime assigns that responsibility.

---

# 11. Department Manager Relationship

A Department may have:

```text id="3zsqya"
manager_id
```

This supports:

```text id="ip6w42"
DEPARTMENT_MANAGER
```

workflow steps.

Department manager access should still be constrained by policy and runtime assignment.

Do not interpret department manager as unrestricted department administrator unless explicitly required.

---

# 12. Approval Assignment Is Authoritative

For approval actions, runtime assignment is the primary authorization source.

Example:

```text id="hd9a4j"
ApprovalAssignment

approver_id = current user
status = PENDING
```

This means the user is currently expected to act.

A user must not be allowed to approve solely because:

```text id="z82yfg"
role = FINANCE
```

if that specific request has not assigned them.

---

# 13. Policy Responsibility

Laravel Policies should protect resource-level operations.

Expected policies include:

```text id="5b74kz"
PurchaseRequestPolicy
SupplierInvoicePolicy
ExpenseClaimPolicy
WorkflowTemplatePolicy
WorkflowVersionPolicy
VendorPolicy
DepartmentPolicy
AttachmentPolicy
ApprovalPolicy
```

The exact list may evolve as implementation becomes clearer.

---

# 14. Policy vs Service Responsibility

Policies answer:

```text id="ajq17k"
May this user attempt this action on this resource?
```

Services answer:

```text id="mp26l7"
Is this action still valid according to current business state?
```

Example:

Policy:

```text id="9aq28i"
User is the assigned approver.
```

Service:

```text id="yhwjyt"
Assignment is still PENDING.
Step is still ACTIVE.
Request is still IN_APPROVAL.
```

Both checks are required.

---

# 15. PurchaseRequestPolicy

Typical abilities:

```text id="1c4g6j"
viewAny
view
create
update
submit
withdraw
```

Possible rules:

## view

Allow when:

- user owns the request
- user currently has approval responsibility
- FINANCE has documented access
- ADMIN has administrative access

---

## update

Allow only when:

```text id="nrsjns"
user owns request
AND
status is editable
```

Typical editable states:

```text id="zhxd9j"
DRAFT
CHANGES_REQUESTED
```

---

## submit

Allow when:

```text id="v404ok"
user owns request
AND
status = DRAFT
```

Business validation still happens in the service.

---

## withdraw

Allow when:

```text id="u36d96"
user owns request
AND
status allows withdrawal
```

---

# 16. ExpenseClaimPolicy

Typical abilities mirror Purchase Request ownership rules.

Employee should be able to:

- view own claim
- edit own draft
- resubmit own changes-requested claim
- withdraw eligible claim

FINANCE access may be broader for operational review.

---

# 17. SupplierInvoicePolicy

Supplier Invoice ownership differs slightly from Expense Claims.

Typical access may include:

- submitter
- department-related users where explicitly allowed
- FINANCE
- assigned approvers
- ADMIN

Do not automatically expose all supplier invoices to all employees.

Supplier invoices may contain commercially sensitive information.

---

# 18. ApprovalPolicy

Approval actions should have dedicated authorization logic.

Typical abilities:

```text id="1t230q"
view
approve
reject
requestChanges
```

At minimum, the acting user must be associated with an eligible ApprovalAssignment.

Example:

```text id="hdj853"
assignment.approver_id === user.id
```

Additional current-state validation belongs in ApprovalService.

---

# 19. Self-Approval

V1 should prevent self-approval.

If:

```text id="2l6lve"
requester_id
=
approver_id
```

the workflow should not allow the approval.

Ideally this is detected during approver resolution.

Authorization should also fail defensively if such an assignment somehow exists.

---

# 20. Role-Based Approval Steps

A ROLE workflow step may assign multiple users.

Example:

```text id="6v8p47"
ROLE = FINANCE
```

Runtime should create ApprovalAssignments for the actual eligible users.

Authorization then operates on those assignments.

Do not re-query:

```text id="x1l4um"
all current FINANCE users
```

every time someone clicks Approve.

Runtime assignment is the source of truth.

---

# 21. Specific User Approval

For:

```text id="km9u3k"
SPECIFIC_USER
```

only the resolved assigned user may act.

Another ADMIN or FINANCE user should not automatically substitute themselves unless an explicit override/delegation feature permits it.

---

# 22. Delegation

Delegation is a future feature.

When delegation exists:

```text id="ldgkux"
John
→ delegates to Mary
```

runtime should reflect who is actually authorized to act.

Authorization should inspect the resulting assignment rather than independently interpreting delegation rules in controllers.

---

# 23. View Permission vs Action Permission

A user may be allowed to view a record without being allowed to approve it.

Example:

```text id="mjobnn"
Finance user can view invoice
```

does not necessarily mean:

```text id="g2qfq6"
Finance user can approve current step
```

These permissions must remain distinct.

---

# 24. List Queries

Do not load all records and then hide unauthorized rows in PHP.

Queries for index pages should be scoped.

Examples:

## My Requests

```text id="fla8k2"
requester_id = current_user
```

## My Approval Inbox

```text id="8q71o4"
ApprovalAssignment.approver_id = current_user
AND
status = PENDING
```

## Finance Workspace

Use explicit finance-scoped queries.

---

# 25. Policy Scope / Query Scope

Where helpful, implement query scopes or dedicated query objects/services to constrain lists.

Do not use Policy checks row-by-row over thousands of records as the primary list-access mechanism.

Policies protect individual resources.

Queries should already return the correct operational scope.

---

# 26. Attachment Authorization

Attachments are private.

A user may download an attachment only if they are allowed to access the parent business record.

Conceptually:

```text id="o6ly72"
Attachment
    ↓
attachable
    ↓
Policy on parent record
```

Do not trust attachment ID alone.

---

# 27. Attachment Example

Employee A tries:

```text id="xbca0v"
/attachments/999/download
```

Attachment 999 belongs to Employee B's Expense Claim.

The server must reject access even if the URL is known.

Do not expose direct public storage URLs.

---

# 28. Workflow Configuration Authorization

Workflow configuration is administrative functionality.

Typical permissions:

```text id="55gzbu"
ADMIN
```

Only authorized administrators should be able to:

- create templates
- create draft versions
- edit draft rules
- edit draft steps
- validate workflow
- publish new version
- archive/disable templates

FINANCE does not automatically receive workflow administration rights unless explicitly required.

---

# 29. Published Workflow Protection

Even ADMIN should not directly edit a published WorkflowVersion.

This is not only authorization.

It is a business invariant.

Correct action:

```text id="g25mfs"
clone
→ edit draft
→ publish new version
```

Authorization cannot override architecture invariants casually.

---

# 30. Vendor Authorization

Typical V1 approach:

```text id="e7owwf"
ADMIN
→ manage Vendors

FINANCE
→ possibly view Vendors
```

Whether FINANCE may edit vendors should be explicitly decided later.

Avoid giving broad write permission without a real workflow requirement.

---

# 31. Department Authorization

Typical:

```text id="78nsl3"
ADMIN
→ manage Departments
```

Employees may read enough department data to create requests.

They do not need department administration access.

---

# 32. Spend Category Authorization

Typical:

```text id="zigm7m"
ADMIN
→ manage categories

Employees
→ use active categories
```

Inactive categories should remain readable for historical records but unavailable for new selection.

---

# 33. Audit Log Authorization

Audit logs may contain sensitive operational information.

Typical:

```text id="kw3bzc"
ADMIN
→ broad audit access
```

FINANCE may receive limited finance audit access later if required.

Do not expose system-wide audit logs to ordinary employees.

---

# 34. Approval Timeline Visibility

If a user may view a business record, they may normally view the approval timeline for that record.

However, be careful with sensitive metadata.

For V1, timeline should show:

- action
- actor name
- timestamp
- business comment

Avoid exposing unnecessary internal metadata or system/debug information.

---

# 35. Requester Identity

Never trust:

```text id="7v1mdc"
requester_id
```

from frontend input for normal employee submission.

For self-service requests:

```text id="n4t3jf"
requester_id = authenticated user
```

should be derived server-side.

Similar rule applies to:

```text id="oeflwp"
employee_id
submitted_by
```

where appropriate.

---

# 36. Department Assignment

Do not blindly trust arbitrary `department_id` if the business rule says the request belongs to the requester's own department.

If users are allowed to submit on behalf of another department, that must be an explicit permission.

The module-specific documentation should define this.

---

# 37. Status Authorization

Never accept frontend fields such as:

```text id="mf5k5x"
status = APPROVED
```

to determine workflow state.

Status changes happen through explicit service actions.

Forms should not contain authoritative status selectors for ordinary users.

---

# 38. Role Assignment Security

Users must not be able to modify:

```text id="p3t43f"
role
```

through ordinary profile/update forms.

Role management belongs to authorized administration flows.

Avoid mass-assignment vulnerabilities around privileged fields.

---

# 39. Mass Assignment

Models should protect sensitive attributes.

Do not blindly call:

```php id="v3iy6w"
$model->update($request->all());
```

Prefer validated, intentional attributes.

Sensitive examples:

```text id="o31ojq"
status
requester_id
approver_id
workflow_version_id
role
approved_at
```

must not be writable through generic user input.

---

# 40. Admin Override

V1 should not include a generic:

```text id="ybkr4j"
Admin can force approve anything
```

feature.

Administrative power should not bypass approval history and workflow correctness by default.

If a future override is required, it should:

- be explicit
- require justification
- create audit history
- preserve original runtime
- be separately authorized

---

# 41. Impersonation

Do not implement user impersonation in V1.

It complicates:

- audit attribution
- approval responsibility
- security

If introduced later, the system must distinguish:

```text id="bhw3gd"
actual actor
vs
impersonated user
```

in audit records.

---

# 42. Inactive Users

Inactive users must not:

- log in
- create requests
- receive new approval assignments
- approve existing pending assignments

If a user becomes inactive while holding a pending assignment:

the workflow may become BLOCKED or use future delegation/reassignment behavior.

Do not silently continue under an inactive identity.

---

# 43. Inactive Manager

If approver resolution finds an inactive manager:

treat the approver as unavailable.

Do not assign approval to an inactive user.

The workflow should fail or become blocked according to runtime rules.

---

# 44. Request Visibility After User Deactivation

Historical records created by an inactive user must remain available to authorized users.

Never delete business records because their creator was disabled.

Authorization and historical integrity are separate concerns.

---

# 45. Authentication

Authentication is handled by Laravel's authentication stack.

Authorization assumes:

```text id="ywio2h"
authenticated active user
```

for protected application routes.

Administrative and business routes should use appropriate authentication middleware.

---

# 46. Route Middleware

Middleware may enforce broad requirements such as:

```text id="l0r1zx"
auth
verified
active user
admin area
```

Resource-specific decisions still belong in Policies.

Do not encode all business authorization into custom route middleware.

---

# 47. Blade Authorization

Blade may use:

```text id="ay816o"
@can
```

to show or hide actions.

Example:

```text id="oxqc95"
show Edit button only if update allowed
```

But this is not security enforcement.

The controller/service must still authorize the action.

---

# 48. API / HTTP Failure Behavior

Recommended behavior:

```text id="3cly4q"
Unauthenticated
→ 401 / login redirect
```

```text id="py1h9g"
Authenticated but unauthorized
→ 403
```

```text id="k45qwe"
Authorized but business state no longer allows action
→ domain conflict / validation-style response
```

Do not return 403 for every state conflict.

Authorization and business validity are different concepts.

---

# 49. Example: Approve Request

User opens assigned request.

Flow:

```text id="gdamjy"
Route
↓
auth middleware
↓
Policy confirms user may attempt approval
↓
ApprovalService
↓
lock runtime
↓
confirm assignment still belongs to user
↓
confirm step ACTIVE
↓
approve
```

The runtime check is repeated because authorization may have changed since the page was rendered.

---

# 50. Example: Edit Expense Claim

Employee attempts update.

Policy checks:

```text id="prm8qg"
claim.employee_id = current user
```

and:

```text id="vnjyx5"
status in
[DRAFT, CHANGES_REQUESTED]
```

Then service validates and updates allowed business fields.

Employee cannot update:

- approval status
- approver
- audit history
- employee ownership

---

# 51. Example: Finance User

Finance user opens Supplier Invoice.

May be allowed to view because of Finance role.

But Approve button is available only when:

```text id="t8e6o1"
user has active ApprovalAssignment
```

Finance visibility and Finance approval responsibility are separate.

---

# 52. Example: Manager

John manages Alice.

Alice submits Expense Claim.

If current workflow assigns:

```text id="d9699u"
REQUESTER_MANAGER
```

John receives ApprovalAssignment.

John can then:

- view relevant request
- approve
- reject
- request changes

If the workflow does not assign John:

being Alice's manager alone should not automatically let John perform approval actions outside documented behavior.

---

# 53. Sensitive Fields

The following should be protected from ordinary user mutation:

```text id="f7fi4p"
status
workflow_version_id
workflow_rule_group_id
current_step
approval assignment
approval actor
approved_at
rejected_at
requester_id
employee_id
role
```

Set these through trusted server-side code.

---

# 54. Authorization Audit

Important privileged actions should be auditable.

Examples:

```text id="4hx92s"
role changed
workflow published
vendor changed
delegation created
admin override if ever implemented
```

Audit records should identify the actual authenticated actor.

---

# 55. Tests — Ownership

Employee A cannot:

- view private Employee B claim
- edit Employee B request
- withdraw Employee B request

---

# 56. Tests — Draft Editing

Owner may edit:

```text id="qbgu8x"
DRAFT
```

Owner cannot normally edit:

```text id="8tzsnt"
IN_APPROVAL
APPROVED
REJECTED
WITHDRAWN
```

Owner may edit:

```text id="ed6azo"
CHANGES_REQUESTED
```

according to module rules.

---

# 57. Tests — Approval Assignment

Assigned approver may approve.

Unassigned user may not.

Even another user with the same broad role may not act unless runtime assignment allows it.

---

# 58. Tests — Self Approval

Requester cannot approve own request.

---

# 59. Tests — Finance Visibility

Finance receives only the explicitly intended finance-level visibility and actions.

Do not accidentally grant broad write access.

---

# 60. Tests — Admin Workflow Management

ADMIN can:

- create draft workflow
- edit draft
- publish

Non-admin users cannot manage workflow configuration.

---

# 61. Tests — Published Version

Even authorized ADMIN cannot mutate published workflow rules through normal edit flow.

This protects the business invariant.

---

# 62. Tests — Attachment Access

Authorized user can download attachment.

Unauthorized user receives:

```text id="a9zgxl"
403
```

Direct knowledge of attachment ID must not bypass parent authorization.

---

# 63. Tests — Mass Assignment

User-submitted privileged fields such as:

```text id="m1ryya"
status = APPROVED
role = ADMIN
requester_id = another user
```

must not alter authoritative protected values.

---

# 64. Tests — Inactive User

Inactive user:

- cannot access protected application flows
- cannot receive new approval assignment
- cannot approve existing assignment

---

# 65. V1 Authorization Scope

V1 authorization supports:

```text id="q35ezv"
ADMIN
FINANCE
EMPLOYEE

resource ownership

department / manager relationships

runtime approval assignments

Laravel Policies

private attachment authorization
```

This is sufficient for the initial system.

---

# 66. Deferred Authorization Features

Do not implement initially:

- custom permission builder
- per-field permissions
- user-defined roles
- multi-company tenant permissions
- temporary admin impersonation
- complex data-region permissions
- generic ACL engine
- external IAM synchronization
- SSO-specific role mapping

Add them only when a real requirement exists.

---

# 67. Core Principles

Authorization follows these principles:

```text id="0mx86j"
Server-side authorization is authoritative.

Roles provide broad capability.

Relationships provide organizational context.

ApprovalAssignment provides current approval responsibility.

Policies protect resources.

Services protect current business state.

Viewing does not imply approving.

Admin access does not automatically bypass workflow.

Frontend controls are not security.
```

---

# 68. Summary

OpsFlow authorization can be summarized as:

```text id="348u2m"
Authenticated User
      ↓
Broad Role
      +
Ownership / Organization Relationship
      +
Runtime Approval Assignment
      ↓
Laravel Policy
      ↓
Business Service State Validation
      ↓
Allowed Action
```

The most important separation is:

```text id="0tlmuk"
Authorization
≠
Business State Validation
```

A user may be authorized to attempt an action while the action is no longer valid because the workflow has already changed.

Policies decide who may act.

Services decide whether the action may still happen now.