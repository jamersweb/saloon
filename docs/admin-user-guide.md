# Vina Management System - User Guide (Operations)

## 1. Login
1. Open the app URL.
2. Click `Staff Login` (top-right on public page).
3. Enter email and password.
4. You will see menu items based on your role.

## 2. Role Access
- `Owner`: full access to all modules.
- `Manager`: operations + management modules + reporting.
- `Staff`: own workspace only (`Dashboard`, `Attendance`, `Leave Requests`, `Profile`).
- `Customer`: public booking side only.

## 3. Dashboard (Daily Check)
1. Open `Dashboard` after login.
2. Use period filter (`Today`, `This Week`, `This Month`).
3. Review KPI cards.
4. Check upcoming appointment list.

## 4. How to Add a Customer
1. Open `Customers`.
2. Click add/new customer form.
3. Fill required fields:
   - Name
   - Phone
   - Email (optional but recommended)
4. Save.
5. Confirm customer appears in customer list/search.

## 5. How to Create an Appointment
1. Open `Appointments`.
2. In `Create Appointment` section, fill:
   - Customer name/phone/email
   - Service
   - Staff (optional)
   - Start date/time
   - End date/time (optional)
   - Status (`pending` or `confirmed`)
3. Click `Create`.
4. Verify it appears in `Appointment Queue`.

## 6. How to Check and Update Appointments
1. Open `Appointments`.
2. Use `Filter status` to narrow results.
3. In queue, review:
   - Time
   - Customer
   - Service
   - Staff
   - Status
4. Use action buttons:
   - `Edit` to update details
   - `Confirm/Start/Complete` for status transition
   - `Cancel` to cancel appointment

## 7. Booking Rules (Manager/Owner)
1. Open `Appointments` page.
2. In `Booking Rules` section update:
   - Slot interval
   - Min advance time
   - Max advance days
   - Cancellation cutoff
3. Enable/disable:
   - Public booking approval
   - Customer cancellation
4. Click `Save Booking Rules`.

### Booking Rules Details
- `Slot interval (minutes)`:
  - Controls available start-time spacing in booking.
  - Example: `15` means slots like 10:00, 10:15, 10:30.
- `Min advance (minutes)`:
  - Minimum time required before appointment start.
  - Example: `30` blocks booking at 10:50 for an 11:00 slot.
- `Max advance (days)`:
  - Furthest future date allowed for booking.
  - Example: `60` means customer can only book within next 60 days.
- `Cancellation cutoff (hours)`:
  - Latest allowed cancellation before appointment start.
  - Example: `12` means customer cannot cancel if less than 12 hours remain.
- `Public booking requires approval`:
  - If enabled, public bookings are created pending approval workflow.
  - If disabled, valid public bookings can be auto-confirmed based on logic.
- `Allow customer cancellation`:
  - If enabled, customer cancellation is allowed (subject to cutoff).
  - If disabled, only staff/admin can cancel from system side.

### Recommended Default Values
- Slot interval: `15`
- Min advance: `30`
- Max advance: `60`
- Cancellation cutoff: `12`

## 8. Attendance
### For staff
1. Open `Attendance`.
2. Click `Clock In` at shift start.
3. Click `Clock Out` at shift end.
4. Check `Attendance Log` for your records.

### For manager/owner
1. Open `Attendance`.
2. Select a staff profile if needed.
3. Use clock in/out actions.
4. Review late minutes and daily logs.

## 9. Leave Requests
### Submit leave
1. Open `Leave Requests`.
2. Fill start date, end date, and reason.
3. Click `Submit`.

### Review leave (manager/owner)
1. Open `Leave Requests`.
2. In request queue, check pending rows.
3. Click `Approve`, `Reject`, or `Cancel`.

## 10. Services Management
1. Open `Services`.
2. Add or edit service details:
   - Name
   - Duration
   - Price
3. Save changes.
4. Confirm service appears in appointment service dropdown.

## 11. Staff and Schedules
### Staff profiles
1. Open `Staff`.
2. Add/edit staff profile.
3. Assign role where required.

### Work schedules
1. Open `Schedules`.
2. Define shift days/times and breaks.
3. Save schedule.
4. Verify bookings respect shift constraints.

## 12. Inventory, Suppliers, Purchase Orders
### Inventory
1. Open `Inventory`.
2. Add products and update stock.
3. Use stock adjustment action when needed.
4. Scan and resolve low-stock alerts.

### Suppliers
1. Open `Suppliers`.
2. Add or edit supplier records.

### Purchase Orders
1. Open `Purchase Orders`.
2. Create draft PO.
3. Move lifecycle as needed: approve -> receive -> close/cancel.

## 13. Loyalty
1. Open `Loyalty`.
2. Manage tiers and multipliers.
3. Add rewards and redemption rules.
4. Use ledger/manual entries when needed.
5. Confirm points update after appointment completion.

## 14. CRM Automation
1. Open `CRM Automation`.
2. Manage tags (create/assign/remove).
3. Create segment rules and preview result count.
4. Run segment assignment.
5. Create campaign templates and campaigns.
6. Dispatch campaigns manually or scheduled.

## 15. Reports
1. Open `Reports`.
2. Select date range.
3. Review KPI cards/charts.
4. Export data:
   - CSV
   - PDF summary

## 16. Roles and Permissions
1. Open `Roles & Permissions`.
2. Create or edit role.
3. Enable required permissions.
4. Save and test with a user assigned to that role.

## 17. Staff Data Restriction Note
- Staff users are permission-locked from frontdesk modules (`Customers`, `Appointments`).
- Staff can only access own workspace pages and own data scope for attendance/leave.
