# Vina Management System
## Client Admin Guide

Version: March 2026

## 1. Access and Login
1. Open the application URL.
2. Click `Staff Login` (top-right on home page).
3. Sign in with your account.
4. The menu and dashboard depend on your role.

## 2. Roles and What They Can Do
- `Owner`: full control of all modules.
- `Manager`: full operational control (appointments, team, services, inventory, reports, automation).
- `Staff`: personal workspace only (attendance, leave requests, own dashboard data).
- `Customer`: booking and staff review submission.

## 3. Dashboard Overview
1. Open `Dashboard`.
2. Use period filters: `Today`, `This Week`, `This Month`.
3. Review KPI cards and appointment list.
4. Review feedback/review panels:
   - Customer reviews about staff.
   - Staff feedback to customers.

## 4. Customer Management
### Add customer
1. Open `Customers`.
2. Fill required fields:
   - Customer Name
   - Phone
   - Email (recommended)
3. Click `Create`.

### Update customer profile
1. Select a customer from list.
2. Update profile details (birthday, source, allergies, notes).
3. Click `Save Profile`.

## 5. Appointment Management
### Create appointment
1. Open `Appointments`.
2. Fill:
   - Customer
   - Service
   - Staff (optional)
   - Scheduled start/end
   - Status
3. Click `Create`.

### Manage appointment queue
1. Use `Filter Status`.
2. Use actions:
   - `Edit`
   - `Confirm / Start / Complete`
   - `Cancel`

## 6. Booking Rules
1. Open `Appointments` -> `Booking Rules`.
2. Configure:
   - Slot Interval (minutes)
   - Min Advance (minutes)
   - Max Advance (days)
   - Cancellation Cutoff (hours)
3. Set toggles:
   - Public booking requires approval
   - Allow customer cancellation
4. Save rules.

Recommended defaults:
- Slot interval: `15`
- Min advance: `30`
- Max advance: `60`
- Cancellation cutoff: `12`

## 7. Staff and Schedule Management
### Staff profiles
1. Open `Staff`.
2. Create or edit staff member.
3. Assign role.

### Work schedules
1. Open `Schedules`.
2. Define shift times and break windows.
3. Save and verify booking availability.

## 8. Attendance and Leave
### Attendance
1. Staff uses `Clock In` and `Clock Out`.
2. Manager/owner can review logs and late minutes.

### Leave requests
1. Staff submits request with date range and reason.
2. Manager/owner approves or rejects.

## 9. Inventory and Procurement
### Inventory
1. Add items and maintain stock.
2. Use stock adjustment when required.
3. Scan and resolve low-stock alerts.

### Suppliers and purchase orders
1. Maintain supplier records.
2. Create purchase orders.
3. Move PO status through draft, approved, received, or cancelled.

## 10. Loyalty
1. Configure loyalty tiers and rewards.
2. Manage ledger entries and redemption.
3. Verify points updates after completed appointments.
4. For NFC reader usage, follow `docs/nfc-reader-setup.md`.

## 11. CRM Automation
1. Create tags and assign customers.
2. Build segment rules and run previews.
3. Manage due-service reminders.
4. Create campaign templates and campaigns.
5. Dispatch manually or run scheduled dispatch.

## 12. Reports
1. Open `Reports`.
2. Choose date range.
3. Review KPIs and charts.
4. Export CSV or PDF summary.

## 13. Roles and Permissions
1. Open `Roles & Permissions`.
2. Create role or edit existing one.
3. Assign required permissions.
4. Save and verify with a test user.

## 14. Feedback and Review Workflow (New)
### A. Staff -> Customer Feedback
1. Staff opens `Dashboard`.
2. In `Staff Feedback to Customer`:
   - Select customer
   - Write feedback comment
3. Submit.
4. Feedback appears in dashboard list for staff, manager, and owner.

### B. Customer -> Staff Review
1. Customer logs in.
2. In `Review Staff`:
   - Select staff member
   - Give rating (1-5)
   - Add review comment
3. Submit.
4. Review appears in dashboard list for staff, manager, and owner.

## 15. Data Visibility Rules
- Staff sees only own operational data and own feedback context.
- Manager and owner see business-wide feedback and review summaries.
- Customer sees own review form and own submitted review context.

## 16. Daily Recommended Admin Routine
1. Check dashboard KPIs and pending items.
2. Review appointment queue and status transitions.
3. Review leave requests and attendance anomalies.
4. Check feedback/reviews for service quality issues.
5. Resolve inventory alerts.
6. Export daily/weekly reports.
