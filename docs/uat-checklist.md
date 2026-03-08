# UAT Checklist

## A. Authentication and RBAC
- [ ] Owner can access all modules.
- [ ] Manager access excludes owner-only actions (if configured).
- [ ] Staff cannot open owner/manager-only screens.

## B. Booking
- [ ] Public booking works for valid slot.
- [ ] Public booking rejects non-policy slots (advance/interval window).
- [ ] Public booking blocks unavailable staff (shift/break/leave/conflict).
- [ ] Admin booking validates conflicts and staff schedule constraints.
- [ ] Appointment transitions are valid and audit logged.

## C. HRM
- [ ] Clock in/out records attendance.
- [ ] Late minutes are computed from schedule.
- [ ] Leave approval updates visibility and blocks booking availability.

## D. CRM and Automation
- [ ] Tag create/assign/remove works.
- [ ] Segment rule create/edit/deactivate works.
- [ ] Rule preview count and run assignment works.
- [ ] Due-service generation and reminder logging works.
- [ ] Campaign template creation works.
- [ ] Campaign manual dispatch creates communication logs.
- [ ] Scheduled campaigns dispatch by scheduler/command.

## E. Loyalty
- [ ] Wallet auto-creates for new customers.
- [ ] Auto-earn applies on appointment completion.
- [ ] Tier multiplier affects earned points.
- [ ] Bonus settings affect bonus award actions.
- [ ] Rewards redemption updates points and stock.

## F. Inventory and Procurement
- [ ] Product CRUD and stock adjustments work.
- [ ] Low-stock scan/resolve flow works.
- [ ] Purchase order draft edit/approve/receive/cancel lifecycle works.

## G. Reporting
- [ ] KPI cards load for selected date range.
- [ ] Waiting-time and late-minute charts render.
- [ ] CSV exports work for each report type.
- [ ] PDF summary export downloads successfully.

