# VINA CRM Blueprint Checklist

Status key: `[x] Done`, `[-] Partial`, `[ ] Missing`

## 1. CRM Setup Objective
- [-] Revenue, expense, and profitability tracking exists, but not yet fully by blueprint cost center and category.
- [-] Monthly reporting exists, but blueprint-grade line-by-line P&L is only partially implemented.

## 2. Revenue Categories
- [-] Revenue transactions exist through invoices.
- [x] Service income is supported.
- [-] Retail product sales exist on invoice lines, but were not previously categorized explicitly.
- [ ] Package sales are not posted as a dedicated revenue transaction at package sale time.
- [ ] Gift card sales are not posted as a dedicated finance transaction.
- [ ] Chair rental income is not implemented.
- [ ] Line rental income is not implemented.
- [ ] Commission income is not implemented.
- [-] Other income has generic support only.

## 3. Service Lines / Cost Centers
- [-] Service concepts exist across appointments and services.
- [x] Finance structure now includes explicit cost center fields for expense rows and invoice lines.
- [-] Historical data defaults to `General Salon` unless reassigned.

## 4. Expense Categories
- [-] Expenses existed with a simpler taxonomy.
- [x] Blueprint-aligned expense categories are now defined in code.
- [x] Expenses now support explicit cost center assignment.
- [x] Miscellaneous expenses now require written notes.

## 5. Package Management Requirements
- [x] Package creation, assignment, session consumption, expiry, and remaining balances exist.
- [x] Package session usage reduces remaining balance.
- [x] Package sale posting now creates a dedicated finance invoice at assignment time.

## 6. Chair Rental & Line Rental
- [x] Dedicated rental agreement module now exists for chair and line rental.
- [x] Rental settlements can post fixed rental income into finance.
- [x] Commission-from-rented-line workflow now exists through rental settlements with gross sales plus commission percent.

## 7. Inventory Categories
- [-] Inventory exists.
- [-] Blueprint inventory categories are only partially reflected.

## 8. Payment Methods
- [-] Cash, card, bank transfer, gift card, and other existed.
- [x] Finance structure now includes `Online Payment Link`, `Package Credit`, and `Split Payment` options.
- [ ] Split-payment settlement logic is not yet implemented as multi-row guided UX.

## 9. Required Fields for Every Transaction
- [x] Date exists.
- [x] Type exists.
- [-] Main category existed and is now blueprint-aligned for expenses and explicit for invoice lines.
- [-] Cost center/service line is now added structurally, but old records remain defaulted.
- [x] Client/supplier exists.
- [x] Staff/responsible person exists.
- [x] Amount exists.
- [x] Payment method exists.
- [x] Invoice/receipt reference exists.
- [x] Description/notes exists.
- [x] Approval status exists for expenses.

## 10. Recording Rules
- [-] One main category plus one cost center is now structurally supported, but not yet fully enforced across every legacy flow.
- [x] Chair rental and line rental revenue rules are now supported through a dedicated rental module.
- [x] Package sales versus package usage are operationally separated and sale posting now exists in finance.
- [x] Discounts are stored separately.
- [x] Refund/adjustment flow now creates linked negative invoices instead of deleting history.
- [-] Inventory purchases versus consumables are partially separated.
- [ ] Marketing campaign cost linking in finance is still missing.
- [x] Miscellaneous expenses now require explanation.

## 11. Required CRM Reports / Dashboards
- [x] Monthly overview exists.
- [x] Revenue by category is now added to finance dashboard.
- [x] Expense by category is now added to finance dashboard.
- [x] P&L by cost center is now added to finance dashboard.
- [ ] Marketing spend by campaign is missing.
- [-] Rental income now appears through revenue category reporting and a dedicated rental operations screen, but there is no separate analytics report view yet.
- [ ] Client revenue report exists only partially through existing reports.

## 12. Request to CRM Support Team
- [-] Core financial structure is now being implemented in the app.
- [ ] Remaining work is campaign-linked marketing accounting and any deeper rental analytics or automation.
