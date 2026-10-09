# WhatsApp reliability update

The existing YCloud credentials and sender settings are unchanged. This update is code only; it does not send a campaign, replace provider templates, import the supplied SQL dump, or rewrite customer phone records.

## Deploy

1. Briefly stop the application's scheduled dispatches and queue workers using the hosting process manager during deployment. Preserve queued jobs and the database.
2. Deploy the changed application files, the new migration, and the complete rebuilt `public/build` directory (including its manifest). Do not copy the local `.env` over production.
3. In the production application directory, run:

   ```sh
   php artisan migrate --force
   php artisan optimize:clear
   php artisan queue:restart
   ```

4. Resume queue workers and the scheduler using the hosting process manager. Workers must run the new code. Existing jobs are revalidated; invalid legacy jobs become terminal failures rather than repeatedly calling YCloud.
5. In YCloud, verify the existing `/webhooks/whatsapp` endpoint subscribes to both `whatsapp.message.updated` and `whatsapp.inbound_message.received`. Delivery events drive status updates; inbound messages establish the 24-hour reply window. No recorded window means free-form text is blocked, while approved templates can still be sent.
6. Sync templates in CRM Automation. Select an exact approved template and language. Templates absent from a complete sync are unavailable. Do not rename a reference to `test_template` without also matching its IMAGE header and one body variable.
7. Use a dedicated due-service template with exactly three body variables in this order: customer name, service name, due date. The reminder path does not supply media headers or dynamic buttons. Campaigns support zero body variables or one customer-name variable, plus their configured media header. Unsupported mappings are blocked before campaign fanout.
8. Send one template to your own test customer. Confirm acceptance, then delivered/read via the webhook. Reply from that number and confirm a free-form reply is allowed. Only then resume intended campaigns; do not bulk-retry historical failed jobs or expired offers.

## Resulting behavior

- SMS is removed from send controls and rejected by API validation, scheduled dispatch and the delivery service. Historical SMS records remain visible as delivery-unverified.
- The placeholder email transport now reports not configured rather than falsely reporting success. This change does not add an email provider.
- Phone normalization accepts common UAE mobile and international formats, including `00` prefixes, and rejects multiple numbers or extensions. Ambiguous customer fields require manual correction; they are not guessed or concatenated.
- Template creation validates numbered variables, sample count, header samples, HTTPS media and fixed-word density. Provider review still determines approval.
- Template sends validate exact name/language, approval, known WABA, body parameters and media header. Missing dynamic URL-button values are blocked.
- Provider acceptance remains queued with `provider_status=accepted`. The UI displays that provider status. Sent/delivered/read timestamps come from webhooks; `reminder_sent_at` is set only on delivered/read.
- Duplicate reminder clicks and accepted job redeliveries do not submit again. Failed reminders are not automatically re-created every day; correct the cause and use the manual reminder action to create a fresh attempt.
- Permanent configuration, recipient and template errors stop. Explicit rate-limit/server failures use bounded backoff. Marketing limits and the 24-hour window are not bypassed.
- Old delivery timestamps are preserved; there is no assumption that historical SMS or WhatsApp acceptance proves delivery.

## Verification

Automated HTTP-faked regression tests cover SMS blocking, template validation, recipient normalization, reply windows, duplicate jobs, reminder deduplication, webhook ordering, template sync invalidation, and permanent/transient failures. No live customer messages are sent by these tests.

The full suite also contains an unrelated existing package-assignment assertion: `CustomerPortalTest` expects `Package assigned.` while the existing controller returns `Package assigned and sale posted to finance.`
