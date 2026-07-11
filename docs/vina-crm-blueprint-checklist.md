# VINA CRM Blueprint Checklist

Status key: `[x] Done`, `[-] Partial`, `[ ] Missing`

## 1. CRM Setup Objective
- [x] Revenue, expense, and profitability tracking exist in the live app.
- [-] Legacy data still needs normalization for best blueprint accuracy.

## 2. Revenue Categories
- [x] Service income is supported.
- [x] Retail product sales are supported.
- [x] Package sales post into finance.
- [x] Gift card sales post into finance at issue/top-up time.
- [x] Chair rental income is implemented.
- [x] Line rental income is implemented.
- [x] Commission income is implemented.
- [x] Other income is supported.

## 3. Service Lines / Cost Centers
- [x] Finance structure includes explicit cost center fields for invoice lines and expense rows.
- [x] Manual non-service invoice lines require explicit cost center selection.
- [-] Historical rows may still default to `General Salon` until backfilled.

## 4. Expense Categories
- [x] Blueprint-aligned expense categories are defined in code.
- [x] Expenses support explicit cost center assignment.
- [x] Miscellaneous expenses require written notes.
- [x] Approval workflow is implemented.
- [x] Campaign-linked expense tracking exists.

## 5. Package Management Requirements
- [x] Package creation, assignment, session consumption, expiry, and remaining balances exist.
- [x] Package session usage reduces remaining balance.
- [x] Package sale posting creates a dedicated finance invoice at assignment time.

## 6. Chair Rental & Line Rental
- [x] Dedicated rental agreement module exists for chair and line rental.
- [x] Rental settlements can post fixed rental income into finance.
- [x] Commission-from-rented-line workflow exists through rental settlements.

## 7. Inventory Categories
- [x] Inventory module exists.
- [-] Inventory purchase vs retail stock vs service consumables is now materially separated, but category mapping is still heuristic-based.

## 8. Payment Methods
- [x] Cash, card, bank transfer, online payment link, package credit, gift card, split payment, and other are supported.
- [x] Split-payment settlement logic now exists as a guided multi-row workflow.

## 9. Required Fields for Every Transaction
- [x] Date exists.
- [x] Type exists.
- [x] Main category exists.
- [-] Cost center/service line exists structurally, but old records may still need cleanup.
- [x] Client/supplier exists.
- [x] Staff/responsible person exists.
- [x] Amount exists.
- [x] Payment method exists.
- [x] Invoice/receipt reference exists.
- [x] Description/notes exists.
- [x] Approval status exists for expenses.

## 10. Recording Rules
- [x] One main category plus one cost center is structurally supported.
- [x] Chair rental and line rental revenue rules are supported.
- [x] Package sales versus package usage are separated.
- [x] Discounts are stored separately.
- [x] Refund/adjustment flow creates linked negative invoices.
- [x] Inventory purchases versus consumables are materially separated.
- [x] Marketing campaign cost linking in finance exists.
- [x] Miscellaneous expenses require explanation.

## 11. Required CRM Reports / Dashboards
- [x] Monthly overview exists.
- [x] Revenue by category exists.
- [x] Expense by category exists.
- [x] P&L by cost center exists.
- [x] Marketing spend by campaign exists.
- [x] Rental analytics report exists.
- [x] Client revenue report exists.

## 12. Request to CRM Support Team
- [x] Core financial structure requested by the blueprint is implemented in the app.
- [-] Remaining work is mainly historical backfill, taxonomy cleanup, and reporting accuracy review.

## Practical Remaining Work
- [-] Run finance-dimension backfill after review of default-cost-center rows.
- [-] Continue reducing heuristic inventory classification by tightening item taxonomy over time.
