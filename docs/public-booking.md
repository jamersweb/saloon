# Public booking

The public `/book` and `/embed/book` routes use `PublicReservationController`. The authenticated portal appointment controller, views, models, and booking rules were not changed. No live website was deployed.

## Data and separation

The public UI reads active `salon_services` records, including their existing prices, categories, durations, and preparation buffers. Public category labels consolidate the existing categories without modifying their records. Zero-price or invalid-duration services require contacting the salon. Prices in the generated concept are illustrative; the implementation uses database values.

Public reservations live in `public_reservations`; they never create portal appointments or alter customer records. Existing staff hours, breaks, approved leave, blocked time and portal appointments are read for availability. Missing shifts remain unavailable. Use the existing service and schedule administration to configure these values.

**Separate calendars are not bidirectionally synchronized.** Public checkout rejects conflicts already in the portal and rechecks them after payment. The unchanged portal does not know about public reservations and can subsequently create a conflicting appointment. Managers must consult `/public-booking-management` before making portal bookings. Full cross-system prevention requires a later explicitly scoped integration.

Public writes serialize on the selected staff row using database transactions and row locks. Use MySQL/InnoDB in production. SQLite tests exercise conflict behavior but do not prove production concurrency under load.

## Activation

1. Deploy the new PHP classes, views, public CSS/JS, configuration and route changes to the intended public application. Assets are standalone; a Vite rebuild is not required for these pages.
2. Apply the additive migration: `php artisan migrate --path=database/migrations/2026_11_12_000001_create_public_reservations_table.php`.
3. Configure `NOMOD_HOSTED_CHECKOUT_API_KEY` using a dedicated Nomod Hosted Checkout key. Keep it server-side. Configure an HTTPS `APP_URL` and the salon timezone (`APP_TIMEZONE=Asia/Dubai`).
4. Configure a working Laravel mail transport and sender. The current local environment uses `MAIL_MAILER=log`, which does not deliver customer email.
5. Run Laravel's scheduler every minute (`php artisan schedule:run`) to verify payments and retry email delivery when the customer does not return. `php artisan public-booking:reconcile` can also be run manually.
6. Before enabling real bookings, run a provider-approved payment test, verify email delivery, check the real staff calendars, and review duplicate/zero-price/per-piece catalogue entries. No real charge was made during development.

The iframe continues to use `/embed/book`. Cross-site browsers may block session cookies; the form includes an “Open booking in a new tab” fallback. CSRF protection remains enabled. Checkout navigates to Nomod outside the iframe. Test the actual salon website's iframe sandbox and cookie policy before rollout.

## Payment behavior

The server snapshots service names, prices and durations, computes amounts in minor currency units, and calculates a rounded 15% deposit. It reserves the slot before opening checkout. A return URL or client-supplied amount cannot confirm a reservation. The server retrieves checkout from Nomod and matches checkout ID, reference, currency, amount and paid status.

Cancelled/expired provider sessions release their reservation. Open sessions remain held until Nomod reports a terminal state; they are **not blindly expired locally**, which could permit payment for a released slot. Managers should review abandoned provider sessions in Nomod. Automatic time-based cancellation is not enabled because the published archive endpoint does not specify guarantees about concurrent payment completion. If verification fails or a paid slot is no longer valid, do not tell the customer it is confirmed; paid conflicts appear as `payment_review` for management resolution/refund through Nomod.

The initial customer notification is email. WhatsApp was not changed. Delivery failures remain retryable. A transport that accepts an email and then times out can still cause a duplicate on retry, as with ordinary at-least-once email delivery.

Provider references: [Create checkout](https://nomod.com/docs/api-reference/create-checkout), [Retrieve checkout](https://nomod.com/docs/api-reference/retrieve-checkout), [Authentication](https://nomod.com/docs/api-reference/authentication).

## Rescheduling policy assumption

The initial brief's “14 days before” wording was ambiguous. Implemented assumption: a replacement time within 14 days either side of the original appointment, subject to availability; requests less than 24 hours before the existing start require approval. The original date anchors subsequent changes, preventing repeated extensions. The configuration lives in `config/public_booking.php`.

The same reservation and deposit are retained. A normal move changes staff/start/end atomically. Approval requests retain the old reservation and do not hold the proposed new slot. Owners/managers approve or decline requests at `/public-booking-management`; approval rechecks availability. Customer confirmation email is sent after an approved move. Declines are reflected on the private booking page. Refunds and cancellation policy remain management operations.

## Verification

39 targeted tests / 180 assertions passed, covering public pages, server-side totals, payment matching/idempotency, missing credentials, multiple services/buffers, exact consecutive availability, shift ends/breaks/leave/roles, conflict rejection, unchanged portal records, rescheduling, manager approval, and the existing schedule/appointment behavior. Obsolete public-form expectations (unpaid portal appointment creation and implicit schedule creation) were replaced with the new public-reservation contract; the internal appointment-update regression remains.

Browser QA used the Codex in-app browser with an isolated SQLite preview and a copy of all 233 local active catalogue entries. No production data was written. Checked category switching, multi-selection, totals, available times, disabled/unavailable states, form submission with missing payment configuration, desktop and mobile layout. Provider responses were mocked in backend tests. Live Nomod charges and external email delivery remain unverified pending configuration.

### Visual review

Concept: `output/public-booking/design-concept.png` (built-in Image Gen). Prompt: premium VINA transactional booking screen; warm #f1ece9, espresso text, burgundy actions, serif headings, visible categories, ruled service rows, sticky summary, date/time and customer details; no search or marketing hero.

Final screenshots (1488 × 1058 desktop and 390 × 844 mobile; Playwright capture fallback because the in-app browser export was scaled/cropped): `output/public-booking/desktop.png` and `mobile.png`. Compared concept and implementation with `view_image`: headline and wordmark hierarchy; warm background/burgundy selections; ruled service rows and square selection controls; two-column desktop/sticky summary; deposit and balance hierarchy; responsive category wrapping and stacked summary. No horizontal overflow at the observed mobile viewport.

Intentional adaptations: real catalogue names/prices/durations replace sample content; eight real category groups replace five examples; six initial rows plus progressive disclosure accommodate the large catalogue; sans-serif utility text improves dense-control readability; preparation time, validation, privacy and rescheduling disclosures support the actual workflow. Above-fold brand, title, subtitle and primary action match the concept; extra category labels come from the existing catalogue. This is a functional adaptation of the concept, not an exact pixel reproduction.
