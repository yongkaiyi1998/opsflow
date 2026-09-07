# Project

OpsFlow is a Laravel 13 Spend Management & Approval Workflow Platform.

## Stack

- PHP 8.3+
- Laravel 13
- MySQL
- Blade + Bootstrap 5
- PHPUnit
- Queue + Scheduler

Do not add new dependencies or infrastructure unless required.

## Architecture

- Keep controllers thin.
- Use FormRequests for validation.
- Use Policies for authorization.
- Put business workflows in Services.
- Use PHP Enums for important statuses.
- Follow Laravel conventions.
- Do not introduce Repository Pattern without a concrete need.
- Prefer coherent vertical slices over layer-by-layer delivery.

## Business Rules

- Never trust frontend totals, ownership, roles, approvers, or statuses.
- Money must use DECIMAL, never FLOAT/DOUBLE.
- Approval state changes must go through ApprovalService.
- Workflow configuration and runtime approval data must remain separate.
- Existing approval instances retain the workflow version used at submission unless documented resubmission rules create a new runtime.
- Do not hard-delete submitted business records or approval/audit history.
- AI is advisory only and must not make final approval decisions.

## Transactions & Concurrency

Use DB transactions for multi-record business workflows such as:

- submit
- approve
- reject
- request changes
- resubmit
- withdraw

Use locking when concurrent actions could advance approval state twice.

Always re-check persisted state after locking.

Keep transactions short and do not perform slow external calls inside critical transactions.

## Execution Style

- Complete one coherent task or vertical slice within the given scope.
- Make reasonable Laravel-conventional decisions without asking for confirmation unless genuinely blocked.
- For routine CRUD, migrations, Blade UI, Policies, FormRequests, and standard tests, keep analysis lightweight and implement directly.
- Do not stop after every model, controller, service, or view layer unless the task explicitly requires it.
- Do not repeatedly re-read unrelated documentation.
- Use the task's listed documentation as the primary context.
- After implementation, run focused tests and fix confirmed failures before finishing.

## Scope

- Modify only files required by the task.
- Do not perform unrelated refactors.
- Do not redesign documented architecture unless a concrete issue requires it.
- Do not scan unrelated modules by default.
- Prefer the smallest coherent change that fully completes the task.
- Do not add speculative abstractions or features for possible future requirements.

## Testing

- Add/update relevant Feature tests.
- Run the smallest relevant test target first.
- Run related module tests after focused tests pass.
- Do not run the full suite after every small change.
- Run the full suite at meaningful integration checkpoints.
- Never weaken a correct test just to make it pass.

## Completion

Before finishing:

- the requested task is complete end-to-end within scope
- relevant tests pass
- authorization and validation are enforced
- documented business invariants are preserved
- no debug code or secrets remain
- no unrelated files changed without reason

Do not stop for confirmation over routine Laravel implementation decisions unless genuinely blocked.

Report:

1. changed files
2. key decisions
3. tests run
4. assumptions or remaining risks