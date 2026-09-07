# Workflow Rules

## 1. Purpose

Workflow Rules determine which approval route should apply to a submitted business record.

They answer questions such as:

- Is the amount above a threshold?
- Does the request belong to a specific department?
- Does the request use a specific spend category?

Rules are evaluated by `WorkflowResolver`.

They do not approve requests and do not create runtime approval records.

---

# 2. Rule Structure

Rules are grouped inside a `WorkflowRuleGroup`.

Conceptually:

```text
WorkflowVersion
    ↓
WorkflowRuleGroup
    ↓
WorkflowRule
```

Each Rule Group represents one routing scenario.

Example:

```text
High Value IT Purchase

amount > 10000
AND
department = IT
```

---

# 3. Rule Group Behavior

V1 uses a deliberately simple model:

```text
rules inside one group
→ AND

different groups
→ evaluated by priority

first matching group
→ selected
```

Do not implement arbitrary nested Boolean logic in V1.

---

# 4. Example Rule Groups

Example Workflow Version:

```text
Priority 10
IT High Value

amount > 10000
AND
department = IT
```

```text
Priority 20
General High Value

amount > 10000
```

```text
Priority 30
Medium Value

amount >= 1000
AND
amount <= 10000
```

```text
Priority 999
Default
```

If a request is:

```text
amount = 15000
department = IT
```

both Priority 10 and Priority 20 match.

Priority 10 wins.

---

# 5. Priority

`WorkflowRuleGroup.priority` determines evaluation order.

Rule:

```text
lower number
=
higher priority
```

Recommended examples:

```text
10
20
30
40
999
```

Avoid relying on database insertion order.

Evaluation must always be deterministic.

Recommended ordering:

```text
priority ASC
id ASC
```

---

# 6. Default Rule Group

A Rule Group may be marked:

```text
is_default = true
```

The default group contains no required matching conditions.

It is used only when no non-default group matches.

Recommended behavior:

```text
evaluate non-default groups first
↓
if none match
↓
select default group
```

A Workflow Version must have at most one default group.

---

# 7. No Matching Rule

If:

- no Rule Group matches
- no default group exists

then workflow resolution fails.

The request must not silently bypass approval.

Recommended user-facing message:

```text
No approval workflow is configured for this request.
Please contact an administrator.
```

The business record should remain unsubmitted.

---

# 8. WorkflowContext

Rules must evaluate against a normalized `WorkflowContext`.

Example:

```text
WorkflowContext
---------------
module_type
requester_id
department_id
category_id
amount
currency
```

The rule engine should not read arbitrary model fields dynamically.

This keeps routing predictable and testable.

---

# 9. Supported Fields

V1 supports:

```text
amount
department
category
```

Internally, use stable field identifiers such as:

```text
amount
department_id
category_id
```

Do not allow users to submit arbitrary database column names.

Supported fields must come from a whitelist.

---

# 10. Amount

`amount` represents the authoritative backend-calculated total used for approval routing.

Examples:

```text
Purchase Request
→ total_amount

Supplier Invoice
→ total_amount

Expense Claim
→ total_amount
```

The frontend must not decide the amount used by workflow routing.

---

# 11. Department

Department rules evaluate the business record's effective department.

Example:

```text
department = IT
```

The rule should normally store:

```text
department_id
```

rather than department name.

Names may change.

Primary identifiers are more stable.

---

# 12. Category

Category rules evaluate the relevant Spend Category.

Example:

```text
category = SOFTWARE
```

Store the category identifier internally.

The admin UI may display a readable category name.

---

# 13. Supported Operators

V1 supports:

```text
=
!=
>
>=
<
<=
IN
```

Not every operator is valid for every field.

---

# 14. Operator Compatibility

Recommended compatibility:

| Field | Operators |
|---|---|
| amount | `=`, `!=`, `>`, `>=`, `<`, `<=` |
| department | `=`, `!=`, `IN` |
| category | `=`, `!=`, `IN` |

Reject unsupported combinations during configuration validation.

Example:

```text
department > 5
```

is invalid.

---

# 15. Rule Value Storage

A rule may store its value in JSON.

Examples:

Amount:

```json
10000
```

Department:

```json
12
```

Multiple categories:

```json
[2, 5, 8]
```

The evaluator must cast values according to the rule field.

Do not compare all JSON values as strings.

---

# 16. Decimal Comparison

Money comparisons must avoid floating-point behavior.

Conceptually:

```text
15000.00 > 10000.00
```

should be evaluated using a fixed-decimal-safe strategy.

Do not convert authoritative monetary values to binary floating point just for rule evaluation.

The exact PHP implementation may use normalized decimal strings or another safe approach consistent with the application's money handling.

---

# 17. Equality

Example:

```text
department = 5
```

matches when:

```text
context.department_id = 5
```

For identifiers, use strict normalized comparisons.

Avoid loose comparisons that make values such as:

```text
"5"
5
```

behave unpredictably across different code paths.

Normalize before comparing.

---

# 18. IN Operator

Example:

```text
category IN [SOFTWARE, EQUIPMENT, SERVICES]
```

Internally:

```json
[2, 5, 8]
```

The context value matches if it exists in the configured set.

`IN` values must be non-empty arrays.

---

# 19. Null Values

V1 should avoid configurable null-specific logic unless required.

If a context field required by a rule is null:

the rule does not match.

Example:

```text
rule:
category = SOFTWARE

context.category_id = null

→ false
```

Do not treat null as zero or empty string.

---

# 20. RuleEvaluator

A dedicated component should evaluate individual rules.

Conceptually:

```php
RuleEvaluator::matches(
    WorkflowRule $rule,
    WorkflowContext $context
): bool
```

Responsibilities:

- validate field support
- retrieve normalized context value
- normalize configured value
- apply the correct comparison
- return boolean

It should not select Rule Groups.

---

# 21. RuleGroupEvaluator

A Rule Group matches only when all of its non-default rules match.

Conceptually:

```php
RuleGroupEvaluator::matches(
    WorkflowRuleGroup $group,
    WorkflowContext $context
): bool
```

For V1:

```text
rule 1 = true
AND
rule 2 = true
AND
rule 3 = true

→ group matches
```

One false rule means the group does not match.

---

# 22. Empty Non-Default Rule Group

A non-default Rule Group with zero rules is invalid.

Otherwise it would match every request.

Validation must reject it before publication.

Only the explicitly marked default group may have no rules.

---

# 23. WorkflowResolver Matching Flow

Recommended flow:

```text
Load active published WorkflowVersion
        ↓
Load non-default Rule Groups
ordered by priority ASC, id ASC
        ↓
Evaluate Group 1
        ↓
match?
 ├─ yes → return Group 1
 └─ no
        ↓
Evaluate Group 2
        ↓
...
        ↓
No group matched
        ↓
Use default group
        ↓
or fail resolution
```

Stop evaluating after the first match.

---

# 24. First Match Wins

The engine intentionally uses:

```text
first match wins
```

instead of trying to combine multiple matching Rule Groups.

This makes routing easier to reason about.

Example:

```text
Priority 10
IT + amount > 10000

Priority 20
amount > 10000
```

An IT request over RM10,000 uses Priority 10 only.

Its steps are not merged with Priority 20.

---

# 25. Overlapping Rules

Overlapping Rule Groups are allowed.

Example:

```text
Group A
amount > 5000

Group B
amount > 10000
```

A RM15,000 request matches both.

Priority decides which route is used.

The admin UI may warn about obvious overlap, but V1 does not require a mathematical overlap solver.

---

# 26. Bad Priority Example

This configuration is probably wrong:

```text
Priority 10
amount > 5000

Priority 20
amount > 10000
```

A RM20,000 request will always match the first group.

The more specific RM10,000 rule never runs.

Better:

```text
Priority 10
amount > 10000

Priority 20
amount > 5000
```

Specific conditions should generally have higher priority.

---

# 27. Rule Specificity

Priority is explicit.

The system must not automatically calculate "specificity."

Do not create hidden behavior such as:

```text
more rules automatically win
```

Administrators control routing order through priority.

This is easier to understand and test.

---

# 28. Rule Configuration Validation

Before publishing, validate:

- field is supported
- operator is supported
- field/operator combination is valid
- value exists
- value has correct type
- `IN` uses a non-empty array
- amount rule uses valid decimal input
- referenced department exists
- referenced category exists
- non-default group has at least one rule

Invalid rules prevent publication.

---

# 29. Reference Validation

For:

```text
department = 12
```

Department 12 must exist.

For:

```text
category IN [2, 5, 8]
```

all referenced categories should exist.

If referenced master data later becomes INACTIVE:

the published workflow remains historically valid.

Whether inactive master data should still match new requests depends on whether new business records are allowed to use that master data.

Normally they should not.

---

# 30. Published Rules Are Immutable

Rules belonging to a PUBLISHED Workflow Version must not be edited.

If routing changes:

```text
clone version
↓
edit draft rules
↓
publish new version
```

This preserves the routing logic used by historical approvals.

---

# 31. Rule Evaluation Must Be Deterministic

For the same:

```text
Workflow Version
+
WorkflowContext
```

the same Rule Group must always be selected.

Do not use:

- AI
- random values
- external APIs
- current time, unless a future explicit rule type requires it
- mutable request-independent data

inside V1 rule evaluation.

---

# 32. AI Must Not Route Approvals

AI may later suggest:

```text
This appears to be a software expense.
```

But authoritative routing must use validated system fields.

Example:

```text
category_id = SOFTWARE
amount = 15000
```

Then deterministic rules decide the route.

AI output alone must not bypass or determine authoritative approval routing.

---

# 33. Example: Purchase Request

Context:

```text
module_type = PURCHASE_REQUEST
amount = 18000
department_id = IT
category_id = SOFTWARE
```

Groups:

```text
Priority 10

amount > 10000
AND
department = IT
AND
category = SOFTWARE
```

```text
Priority 20

amount > 10000
```

```text
Priority 999

DEFAULT
```

Result:

```text
Priority 10 selected
```

---

# 34. Example: Expense Claim

Context:

```text
amount = 320
department_id = SALES
category_id = TRAVEL
```

Groups:

```text
Priority 10
amount > 5000
```

```text
Priority 20
amount > 1000
```

```text
Priority 999
DEFAULT
```

Result:

```text
DEFAULT
```

---

# 35. Example: IN

Rule:

```text
category IN
[
    SOFTWARE,
    EQUIPMENT,
    PROFESSIONAL_SERVICES
]
```

Context:

```text
category = EQUIPMENT
```

Result:

```text
true
```

---

# 36. Example: AND

Rules:

```text
amount >= 10000
AND
department = FINANCE
```

Context:

```text
amount = 12000
department = HR
```

Evaluation:

```text
amount rule
→ true

department rule
→ false

group
→ false
```

---

# 37. Boundary Conditions

Threshold boundaries must be intentional.

Bad configuration:

```text
Group A
amount < 1000

Group B
amount > 1000
```

What happens at exactly:

```text
1000
```

Neither matches.

Better:

```text
Group A
amount <= 1000

Group B
amount > 1000
```

Tests should include exact threshold values.

---

# 38. Range Example

To represent:

```text
RM1,001 to RM10,000
```

use:

```text
amount > 1000
AND
amount <= 10000
```

Do not add a special BETWEEN operator in V1 unless it materially improves the configuration UI.

Existing operators are sufficient.

---

# 39. Rule Group Naming

Names should explain business intent.

Prefer:

```text
IT High Value Purchase
Standard Expense
Executive Review Required
Default Route
```

Avoid:

```text
Rule 1
Rule 2
Group A
```

unless only used in test fixtures.

Readable configuration improves administration and troubleshooting.

---

# 40. Explanation Support

The system should eventually be able to explain why a route was selected.

Example:

```text
Selected "IT High Value Purchase"
because:

amount RM18,000 > RM10,000
department = IT
category = SOFTWARE
```

V1 does not need a sophisticated explanation engine, but the evaluator should be structured so matched rule data can be surfaced later.

This is useful for:

- admin troubleshooting
- audit
- user explanation
- future AI assistant

---

# 41. Do Not Persist Derived Match Results Into Configuration

Do not write runtime data such as:

```text
last_matched_request_id
last_matched_at
match_count
```

into WorkflowRule configuration rows just because rules are evaluated.

Routing evaluation should not mutate published configuration.

Analytics can be derived from runtime approval data separately.

---

# 42. Performance

Workflow Rule sets are expected to be relatively small.

V1 does not require a complex rule engine cache.

Use eager loading for:

```text
WorkflowVersion
→ Rule Groups
→ Rules
→ Steps
```

Avoid N+1 queries during submission.

Correctness and readability are more important than premature optimization.

---

# 43. Failure Behavior

Unexpected evaluation errors must not result in:

```text
rule skipped
→ request submitted anyway
```

Safer behavior:

```text
workflow resolution fails
↓
submission rolls back
↓
error is logged
```

Approval must fail closed rather than fail open.

---

# 44. Logging

Operational errors worth logging include:

- unsupported field encountered
- invalid operator encountered
- malformed rule value
- no default route when required
- no matching route
- inconsistent published workflow data

Do not log sensitive document contents unnecessarily.

---

# 45. Tests — RuleEvaluator

Test each supported operator.

Examples:

```text
amount > threshold
amount >= threshold
amount < threshold
amount <= threshold
department =
department !=
category IN
```

Also test:

- invalid null context
- invalid value type
- decimal boundaries

---

# 46. Tests — AND Behavior

Given:

```text
amount > 10000
AND
department = IT
```

test:

```text
true + true
→ match
```

```text
true + false
→ no match
```

```text
false + true
→ no match
```

---

# 47. Tests — Priority

Given:

```text
Priority 10
amount > 10000
AND department = IT

Priority 20
amount > 10000
```

an IT request of RM15,000 must select:

```text
Priority 10
```

---

# 48. Tests — Default

No non-default group matches.

Default exists.

Result:

```text
default selected
```

---

# 49. Tests — No Route

No non-default group matches.

No default exists.

Result:

```text
workflow resolution fails
```

No ApprovalInstance should be created.

---

# 50. Tests — Threshold Boundaries

Test exact boundaries.

Example:

```text
<= 1000
> 1000
```

Test:

```text
999.99
1000.00
1000.01
```

This protects against routing gaps.

---

# 51. Tests — IN

Given:

```text
category IN [2, 5, 8]
```

test:

```text
5
→ match
```

```text
4
→ no match
```

---

# 52. V1 Rule Scope

V1 supports only:

```text
Fields:
amount
department
category

Logic:
AND within group

Group selection:
priority + first match wins

Fallback:
one optional default group
```

This is intentionally limited.

---

# 53. Deferred Rule Features

Do not implement initially:

- OR inside a group
- nested Boolean expressions
- NOT groups
- BETWEEN operator
- string pattern matching
- date-based rules
- vendor-based routing
- dynamic expressions
- custom PHP expressions
- JavaScript rules
- AI rule evaluation
- external data lookups

Add them only when a real use case justifies the complexity.

---

# 54. Summary

Workflow Rules follow this evaluation model:

```text
WorkflowContext
      ↓
Published WorkflowVersion
      ↓
Rule Groups ordered by priority
      ↓
Rules inside each group use AND
      ↓
First matching group wins
      ↓
Otherwise use default
      ↓
Otherwise fail safely
```

Core principles:

```text
Rules are deterministic.

Rules use normalized context.

Fields and operators are whitelisted.

Money comparisons are decimal-safe.

Priority is explicit.

Published rules are immutable.

No matching route never means no approval.

AI does not make authoritative routing decisions.
```

The goal is not to build a generic rules language.

The goal is to provide a small, predictable, and easy-to-administer routing system for real approval workflows.