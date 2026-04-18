# Vina Management System
## Staff & Manager Operations Manual (Detailed)

Version: April 2026
Audience: `staff`, `manager` (and `owner` where noted)

---

## 1) Purpose of this manual

This manual explains:
- What each module does
- Which role should use it
- What each setting means
- Step-by-step operating flows for daily work

Use this as your standard SOP for onboarding and daily operations.

---

## 2) Role permissions (quick matrix)

### Staff
- Dashboard (limited personal view)
- Attendance (clock in/out)
- Leave requests (create/view)
- Frontdesk actions if enabled by permissions

### Manager
- Full operations: customers, appointments, attendance, leave
- Services, staff, schedules, inventory, suppliers, purchase orders
- Loyalty, CRM automation, reports, finance
- Can review leave requests

### Owner
- Everything managers can do
- Roles & permissions
- System-level controls

---

## 3) Navigation map

Desktop menu groups:
- `Overview`: Dashboard, daily backup (if enabled)
- `Operations`: Customers, Appointments, Attendance, Leave Requests
- `Management`: CRM Automation, Services, Staff, Schedules, Inventory, Suppliers, Purchase Orders
- `Loyalty`: Program & tiers, Membership cards, Packages, Gift cards, Rewards, Points & ledger
- `Insights`: Reports
- `Finance`: Overview, Invoices, Expenses, Payroll, Finance settings
- `Access`: Roles & permissions (owner/admin)

---

## 4) Daily workflow (recommended)

### Opening routine (manager/frontdesk)
1. Open `Dashboard` and check KPI cards.
2. Open `Appointments` and confirm today’s queue.
3. Open `Attendance` for late/missing clock-ins.
4. Open `Inventory` and resolve low stock alerts.
5. Check `Loyalty` redemptions and pending card tasks if your salon uses loyalty heavily.

### Closing routine
1. Ensure all completed appointments are marked complete.
2. Check gift card/package consumption logs.
3. Export daily report (`Reports`).
4. Trigger/download backup if assigned to reception/manager.

---

## 5) Customers module

### What it does
Stores customer profile, contact details, service context, loyalty context, and portal link.

### Key actions
- Create customer
- Edit profile (birthday, notes, source, etc.)
- Generate customer portal token/link

### Step-by-step: create customer
1. Go to `Customers`.
2. Click create/add.
3. Fill required fields (name, phone).
4. Add optional fields (email, notes, birthday).
5. Save.

### Step-by-step: issue portal link
1. Open customer row.
2. Click `Generate Portal Token`.
3. Copy the portal URL and share with customer.

---

## 6) Appointments module

### What it does
Manages the complete booking lifecycle from creation to completion.

### Status flow
Common statuses: pending -> confirmed -> in progress -> completed (or cancelled).

### Step-by-step: create appointment
1. Go to `Appointments`.
2. Select customer and service.
3. Assign staff (optional/required by salon process).
4. Set date/time.
5. Save as pending or confirmed.

### Step-by-step: service completion
1. Find appointment.
2. Click `Start Service` when customer begins.
3. Click `Complete Service` after finishing.
4. Confirm billing/loyalty behavior:
   - If paid by gift card, enable no-auto-loyalty where applicable.

### Booking rules settings (under schedules/booking rules)
- `Slot interval`: booking grid size in minutes.
- `Min advance`: minimum lead time before a booking is allowed.
- `Max advance`: how many days ahead booking is allowed.
- `Cancellation cutoff`: latest time customer can cancel.
- Toggles:
  - Public booking requires approval
  - Allow customer cancellation

Recommended defaults:
- Slot interval `15` or `30`
- Min advance `30`
- Max advance `60`
- Cancellation cutoff `12`

---

## 7) Attendance & leave

### Attendance (staff + manager)
What it does: tracks daily time logs.

#### Staff steps
1. Open `Attendance`.
2. Click `Clock In` on arrival.
3. Click `Clock Out` before leaving.

#### Manager steps
1. Review logs daily.
2. Investigate missing clock-ins/outs.
3. Correct anomalies per HR policy.

### Leave requests
What it does: planned absence workflow.

#### Staff steps
1. Open `Leave Requests`.
2. Create request with date range + reason.
3. Submit.

#### Manager steps
1. Open pending requests.
2. Approve/reject.
3. Communicate decision to staff.

---

## 8) Services, staff, schedules

### Services
What it does: service catalog with duration/pricing.

Steps:
1. Go to `Services`.
2. Add service name, duration, price, buffer.
3. Set active/inactive.

### Staff
What it does: employee profile and role mapping.

Steps:
1. Go to `Staff`.
2. Create/update profile.
3. Link user and role.

### Schedules
What it does: defines staff availability used by booking.

Steps:
1. Go to `Schedules`.
2. Create shift for staff/date.
3. Add break windows.
4. Save and test booking availability.

---

## 9) Inventory, suppliers, purchase orders

### Inventory
What it does: tracks stock and adjustments.

Key settings/actions:
- Add item with initial stock and thresholds.
- Adjust stock for usage, wastage, or correction.
- Scan low-stock alerts and resolve.

### Suppliers
What it does: supplier records for procurement.

Steps:
1. Add supplier contact details.
2. Keep status active/inactive.

### Purchase orders
What it does: controls procurement cycle.

Status flow:
- Draft -> Approved -> Received (or Cancelled)

Steps:
1. Create PO from supplier.
2. Add items/qty/cost.
3. Approve.
4. Mark received on delivery.

---

## 10) Loyalty module (all sections)

Loyalty has dedicated sections in menu:
- Program & tiers
- Membership cards
- Packages
- Gift cards
- Rewards
- Points & ledger

### 10.1 Program & tiers

#### What it does
Controls point earning rules and customer tiers.

#### Settings explained
- `Auto earn enabled`: if ON, completed appointments can add points.
- `Points per currency`: points awarded per money unit spent.
- `Points per visit`: fixed points per completed visit.
- `Minimum spend`: minimum bill to qualify for earn.
- `Rounding mode`: floor / round / ceil for calculated points.
- `Birthday bonus points`: extra points for birthday events.
- `Referral bonus points`: extra points for referrals.
- `Review bonus points`: extra points for reviews.

#### Tier settings
- `Min points`: threshold to enter tier.
- `Discount %`: automatic discount benefit.
- `Earn multiplier`: points multiplier for this tier.
- `Active`: whether tier is currently used.

### 10.2 Membership cards

#### What it does
Manages membership card types, issuance, assignment, NFC linking.

#### Card number rules
- Numeric only
- Sequential auto-number per card type

#### Step-by-step: pre-issue (inventory) cards
1. Go to `Loyalty -> Membership cards`.
2. In `Pre-issue card`, select card type.
3. Leave card number blank for auto-sequential or enter digits manually.
4. Optional NFC UID.
5. Save.

#### Step-by-step: link pre-issued card to customer
1. Open `Link pre-issued card to customer`.
2. Select customer.
3. Select unassigned card.
4. Save (status usually active).

#### Step-by-step: bind NFC UID
1. Open `Bind / replace NFC UID`.
2. Select membership card.
3. Scan/paste UID.
4. Optional `Replace existing` if UID already assigned elsewhere.
5. Save.

#### NFC actions in registry
- `Copy NFC URL`: copies `/portal/nfc/{UID}` URL
- `Open NFC URL`: opens customer portal by NFC card

### 10.3 Packages

#### What it does
Prepaid package balances (sessions/value).

#### Steps
1. Create package template.
2. Assign package to customer.
3. Consume sessions/value during service.
4. Review customer package table.

### 10.4 Gift cards

#### What it does
Gift value issuance, consumption, NFC mapping.

#### Steps
1. Issue gift card (assigned or unassigned customer).
2. Optional NFC UID bind at issue or later.
3. Consume balance at payment.
4. Optional appointment link to suppress loyalty earn from gift card payment.
5. Review gift card transaction ledger.

### 10.5 Rewards

#### What it does
Catalog of redeemable rewards using points.

#### Reward settings explained
- `Points cost`: points required per unit.
- `Stock quantity`: remaining stock (optional unlimited).
- `Max units per redemption`: cap per redeem action.
- `Max qty per calendar month`: monthly cap per customer.
- `Min days between`: cooldown days.
- `Requires appointment`: enforce visit-linked redemption.
- `Eligible services`: only these services allow redemption.

#### Steps
1. Create reward with rules.
2. Edit reward when policy changes.
3. Redeem reward for customer and (if needed) link appointment.
4. Check recent redemptions.

### 10.6 Points & ledger

#### What it does
Manual and configured point adjustments with full audit trail.

#### Steps
1. `Award configured bonus` (referral/review/birthday).
2. `Add / deduct points` for manual adjustments.
3. Review `Recent loyalty ledger`.

---

## 11) CRM Automation

### What it does
Campaigns, segmentation, due service reminders, tags.

### Components
- Tags and tag assignment
- Segment rules (create, preview, run)
- Due services generation + reminder sending
- Campaign templates and campaigns
- Scheduled dispatch

### Standard manager flow
1. Create campaign template.
2. Build segment rule.
3. Preview segment.
4. Create campaign from template.
5. Dispatch immediately or schedule.

---

## 12) Reports

### What it does
Business and operational summaries with exports.

### Steps
1. Open `Reports`.
2. Choose date range.
3. Review KPI cards/charts.
4. Export CSV/PDF for management review.

---

## 13) Finance module (manager/owner)

### Sections
- Finance overview
- Tax invoices
- Expenses
- Payroll
- Finance settings

### Tax invoices
Steps:
1. Create draft invoice.
2. Add items/amounts/taxes.
3. Finalize to lock values.
4. Record payments.
5. Email receipt or export PDF.
6. Void only when policy allows.

### Expenses
Steps:
1. Create expense entry.
2. Update with final amount/details.
3. Mark paid when settled.

### Payroll
Steps:
1. Create payroll period.
2. Generate lines.
3. Adjust line items if needed.
4. Lock period.
5. Mark paid.

### Finance settings (what they do)
- Tax and numbering behavior
- Defaults used by invoices/payroll dashboards

---

## 14) Customer public portal & NFC behavior

### Portal URL types
- Token URL: `/portal/{token}`
- NFC URL: `/portal/nfc/{NFC_UID}`

### What customer sees
- Profile info
- Current points/tier
- Points spent and points remaining
- Card info
- Packages and gift card balances
- Service history

---

## 15) Backup & server operations (manager/reception)

### Daily backup
- If permission `can_run_daily_backup` exists, use Dashboard daily backup action.

### Scheduler requirement
- One cron entry (every minute):
  - runs Laravel scheduler
  - actual jobs execute on their configured times

---

## 16) Troubleshooting quick guide

### “No scheduled commands are ready to run”
- Normal outside scheduled times.

### NFC UID already linked
- Use replace option if policy allows.
- Ensure UID is not already used by gift card vs membership card.

### Card lookup by NFC fails
- Confirm UID has no extra spaces.
- Ensure UID is bound on card record.
- Check if card is unassigned inventory.

### User cannot see menu item
- Check role/permission mapping in Roles & permissions.

---

## 17) Onboarding checklist (new staff/manager)

### Staff onboarding
1. Login test
2. Clock in/out test
3. Leave request submission test
4. Appointment lifecycle drill (if frontdesk-enabled)

### Manager onboarding
1. Staff checklist plus:
2. Create/edit customer
3. Create/complete appointment
4. Inventory adjustment + low-stock resolve
5. Loyalty points adjustment
6. Report export
7. Finance module walkthrough

---

## 18) Change log policy for this manual

Update this manual whenever:
- New menu section is added
- Any setting behavior changes
- Status flow changes (appointments/PO/payroll/loyalty)

