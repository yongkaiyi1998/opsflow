# Money Storage Decision

## 1. Decision

OpsFlow stores authoritative monetary values using fixed-precision decimal columns.

Use:

```text
DECIMAL
```

Do not use:

```text
FLOAT
DOUBLE
```

for money.

---

## 2. Standard Money Precision

Recommended default:

```text
DECIMAL(15,2)
```

for values such as:

```text
unit_price
subtotal
tax_amount
total_amount
expense amount
invoice amount
purchase request amount
```

This supports sufficiently large business values while keeping normal 2-decimal currency precision.

---

## 3. Why Not FLOAT / DOUBLE

Binary floating-point values may not represent decimal currency exactly.

Financial values must remain predictable for:

- calculation
- database comparison
- workflow thresholds
- reporting
- audit
- tests

Therefore:

```text
Money → DECIMAL
```

is a business correctness requirement.

---

## 4. Currency

Store currency separately.

Recommended:

```text
currency CHAR(3)
```

Examples:

```text
MYR
USD
SGD
```

V1 primarily uses:

```text
MYR
```

but storing the ISO-style currency code avoids unnecessary schema redesign later.

---

## 5. No Currency Conversion in V1

V1 does not implement:

- FX conversion
- exchange-rate tables
- base-currency reporting
- currency gain/loss

A business record's monetary values are interpreted in its stored currency.

Do not compare amounts across different currencies as though they were equivalent.

---

## 6. One Currency Per Business Record

Recommended V1 rule:

```text
one business record
=
one currency
```

Therefore all line items within a:

- Purchase Request
- Supplier Invoice
- Expense Claim

use the parent record's currency.

Mixed-currency line items are deferred.

---

## 7. Server-Side Authority

Frontend-calculated totals are never authoritative.

The backend calculates:

```text
line subtotal
request subtotal
tax
total
claim total
invoice total
```

Frontend totals exist only for immediate UI feedback.

---

## 8. Never Trust Submitted Totals

Bad:

```text
request.total_amount = request input total_amount
```

Preferred:

```text
load validated line items
↓
calculate authoritative totals
↓
store calculated values
```

This protects:

- approval routing
- reports
- financial integrity

---

## 9. Purchase Request Calculation

For each item:

```text
subtotal = quantity × unit_price
```

Request subtotal:

```text
subtotal = SUM(item subtotals)
```

Request total:

```text
total_amount = subtotal + tax_amount
```

All values are calculated server-side.

---

## 10. Supplier Invoice Calculation

For each invoice item:

```text
subtotal = quantity × unit_price
```

Invoice subtotal:

```text
subtotal = SUM(item subtotals)
```

Invoice total:

```text
total_amount = subtotal + tax_amount
```

Do not trust totals extracted from frontend input.

Future AI extraction may suggest totals, but backend validation remains authoritative.

---

## 11. Expense Claim Calculation

For Expense Claim items:

```text
amount
=
gross amount paid by employee
```

Claim total:

```text
total_amount = SUM(expense_items.amount)
```

If an Expense Item contains:

```text
amount = 100.00
tax_amount = 6.00
```

the contribution to claim total remains:

```text
100.00
```

not:

```text
106.00
```

because tax is treated as a breakdown of the gross amount in V1.

---

## 12. Tax Convention

For Purchase Request and Supplier Invoice:

```text
total_amount = subtotal + tax_amount
```

For Expense Claim:

```text
amount = gross paid amount
```

and:

```text
tax_amount
```

is informational.

These conventions must not be mixed.

---

## 13. Quantity

Purchase Request quantity can initially use:

```text
INT UNSIGNED
```

because requested goods commonly use whole units.

Supplier Invoice quantity may use:

```text
DECIMAL(15,4)
```

to support values such as:

```text
1.5 hours
0.25 service units
2.375 kg
```

---

## 14. Quantity Is Not Money

Do not automatically use:

```text
DECIMAL(15,2)
```

for every quantity.

Quantity precision depends on business meaning.

Money precision and quantity precision are separate decisions.

---

## 15. Calculation Precision

Intermediate calculations should preserve sufficient precision before final money values are stored.

Example:

```text
quantity = 1.2500
unit_price = 19.99
```

Do not convert either value to binary floating point merely for convenience.

Round only according to the documented business rule.

---

## 16. Rounding

V1 uses:

```text
2 decimal places
```

for stored monetary amounts.

Rounding should happen consistently at defined calculation boundaries.

Do not allow different modules to silently use different rounding strategies.

Recommended default:

```text
round monetary result to 2 decimal places
```

using normal financial half-up behavior unless a later accounting requirement specifies otherwise.

---

## 17. Line-Level Rounding

Recommended V1:

```text
line subtotal
=
round(quantity × unit_price, 2)
```

Then:

```text
document subtotal
=
SUM(stored/calculated line subtotals)
```

This produces transparent values that match what users see line by line.

---

## 18. Do Not Recalculate From Display Strings

UI values such as:

```text
RM 1,234.50
```

are presentation.

Business calculations should use normalized numeric values.

Do not parse formatted display strings as the authoritative source.

---

## 19. PHP Representation

Avoid treating PHP binary floats as the authoritative long-lived representation of money.

Values retrieved from DECIMAL columns may be handled as normalized decimal strings.

The application should preserve decimal semantics through calculations.

Do not casually cast:

```php
(float) $amount
```

inside financial business logic.

---

## 20. No New Money Library by Default

Do not add a third-party money package solely because money exists in the application.

V1 should remain dependency-light.

If native implementation becomes error-prone or future requirements add:

- multiple currencies
- complex tax
- FX
- advanced allocation

then adopting a dedicated money library may be justified.

---

## 21. Workflow Thresholds

Approval routing may compare values such as:

```text
total_amount > 10000.00
```

Threshold comparison must use the same decimal-safe representation as stored money.

Do not do:

```text
(float) total_amount > 10000
```

as the architectural default.

---

## 22. Rule Values

Workflow amount rules should store normalized decimal-compatible values.

Example:

```text
10000.00
```

The rule evaluator compares:

```text
business total
vs
configured threshold
```

using deterministic decimal-safe logic.

---

## 23. Input Validation

Money input should validate:

- numeric decimal format
- allowed decimal places
- non-negative/positive requirements
- sensible maximum value

Examples:

```text
unit_price > 0
tax_amount >= 0
expense amount > 0
```

Do not permit arbitrary scientific notation unless explicitly supported.

---

## 24. Negative Money

V1 business entry flows should generally reject negative amounts.

Examples:

```text
unit_price = -100
expense amount = -50
```

are invalid.

Credit notes/refunds are separate financial concepts and are deferred.

Do not overload ordinary invoice or expense rows with negative values to represent them.

---

## 25. Zero Values

Recommended:

```text
line item amount > 0
```

Tax may be:

```text
0.00
```

A zero-value business document should normally not be submitted.

If a future use case requires free items or zero-value documents, document it explicitly.

---

## 26. Database Defaults

Where appropriate:

```text
tax_amount DEFAULT 0.00
```

Avoid nullable money fields when:

```text
0.00
```

has a clear and correct business meaning.

Use nullable only when:

```text
unknown/not applicable
```

is meaningfully different from zero.

---

## 27. Database Constraints

Where supported and practical, add constraints that reinforce business validity.

Examples:

```text
total_amount >= 0
tax_amount >= 0
quantity > 0
```

Application validation still provides user-friendly errors.

Database constraints act as a final integrity layer.

---

## 28. Display Formatting

Formatting belongs to presentation.

Examples:

```text
RM 1,250.00
USD 50.00
```

Stored value remains:

```text
1250.00
```

Do not persist currency symbols inside numeric columns.

---

## 29. Comparing Values

Comparisons should occur after normalization.

Example:

```text
1000
1000.0
1000.00
```

must represent the same monetary value.

String formatting differences should not affect workflow routing.

---

## 30. Audit Values

When monetary changes are written to audit metadata, use normalized decimal strings.

Example:

```json
{
  "old_total": "900.00",
  "new_total": "1200.00"
}
```

This is clearer than storing binary-floating representations.

---

## 31. AI-Extracted Money

Future AI may extract:

```text
subtotal
tax
total
```

from invoices or receipts.

AI values are suggestions.

They must be:

```text
normalized
validated
human-confirmed where appropriate
```

before becoming authoritative business values.

AI extraction does not change money-storage rules.

---

## 32. Tests

Critical tests should cover:

### Backend Authority

Frontend submits incorrect total.

Stored total is calculated from authoritative line values.

### Decimal Calculation

Known quantity × unit-price combinations produce expected 2-decimal subtotal.

### Tax

Purchase Request / Supplier Invoice tax is added exactly once.

### Expense Tax

Expense Claim tax is not added twice.

### Threshold Boundaries

Test routing at values such as:

```text
9999.99
10000.00
10000.01
```

### Negative Values

Negative financial input is rejected.

### Currency

Stored currency remains separate from amount.

---

## 33. Deferred Money Features

Do not implement initially:

- exchange rates
- base currency
- FX conversion
- multi-currency line items
- credit notes
- currency-specific decimal scales
- allocation rounding engines
- advanced tax calculations
- accounting journal precision rules

These should be introduced only with concrete requirements.

---

## 34. Decision Summary

OpsFlow money rules are:

```text
Money uses DECIMAL.

Never use FLOAT / DOUBLE for authoritative money.

Standard money precision is DECIMAL(15,2).

Currency is stored separately.

Backend calculates all authoritative totals.

Purchase Request / Supplier Invoice:
total = subtotal + tax.

Expense Claim:
item amount is gross paid amount.

Workflow thresholds use decimal-safe comparisons.

Frontend and AI values are never authoritative by themselves.
```

The goal is simple:

> A monetary value must mean exactly the same thing in the database, business logic, approval workflow, audit history, and tests.