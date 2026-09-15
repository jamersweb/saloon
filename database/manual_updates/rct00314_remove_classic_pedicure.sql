-- Manual data fix for RCT00314.
--
-- Purpose:
-- Remove the circled Classic Pedicure service line from RCT00314, leave the
-- Classic Manicure as the remaining billed service, and recalculate the invoice
-- and single payment totals.
--
-- Run this on the production/server database after taking a backup.

START TRANSACTION;

SET @invoice_id := (
    SELECT id
    FROM tax_invoices
    WHERE invoice_number = 'RCT00314'
    LIMIT 1
);

SET @invoice_appointment_id := (
    SELECT appointment_id
    FROM tax_invoices
    WHERE id = @invoice_id
    LIMIT 1
);

SET @visit_id := (
    SELECT visit_id
    FROM appointments
    WHERE id = @invoice_appointment_id
    LIMIT 1
);

CREATE TEMPORARY TABLE tmp_rct00314_pedicure_appointments AS
SELECT id
FROM appointments
WHERE visit_id = @visit_id
  AND service_id IN (
      SELECT DISTINCT salon_service_id
      FROM tax_invoice_items
      WHERE tax_invoice_id = @invoice_id
        AND salon_service_id IS NOT NULL
        AND LOWER(REPLACE(description, '  ', ' ')) LIKE '%classic pedicure%'
  );

CREATE TEMPORARY TABLE tmp_rct00314_adjustment_invoices AS
SELECT id
FROM tax_invoices
WHERE related_invoice_id = @invoice_id
  AND adjustment_type = 'refund_adjustment';

-- Remove linked adjustment invoices first. They are no longer needed when the
-- duplicate/wrong service line is physically removed from the original invoice.
DELETE FROM invoice_payments
WHERE tax_invoice_id IN (SELECT id FROM tmp_rct00314_adjustment_invoices);

DELETE FROM tax_invoice_items
WHERE tax_invoice_id IN (SELECT id FROM tmp_rct00314_adjustment_invoices);

DELETE FROM tax_invoices
WHERE id IN (SELECT id FROM tmp_rct00314_adjustment_invoices);

-- Remove the Classic Pedicure line(s) from RCT00314.
DELETE FROM tax_invoice_items
WHERE tax_invoice_id = @invoice_id
  AND LOWER(REPLACE(description, '  ', ' ')) LIKE '%classic pedicure%';

-- Remove execution/material notes for the removed pedicure appointment(s) and
-- cancel those appointment rows so service reports do not recreate them.
DELETE FROM appointment_product_usages
WHERE appointment_id IN (SELECT id FROM tmp_rct00314_pedicure_appointments);

DELETE FROM appointment_service_logs
WHERE appointment_id IN (SELECT id FROM tmp_rct00314_pedicure_appointments);

UPDATE appointments
SET status = 'cancelled',
    cancellation_reason = 'Removed from RCT00314 service report per client request',
    updated_at = NOW()
WHERE id IN (SELECT id FROM tmp_rct00314_pedicure_appointments);

-- Recalculate RCT00314 from the remaining invoice item(s).
SET @new_subtotal := (
    SELECT COALESCE(ROUND(SUM(line_subtotal), 2), 0)
    FROM tax_invoice_items
    WHERE tax_invoice_id = @invoice_id
);

SET @new_vat := (
    SELECT COALESCE(ROUND(SUM(line_tax), 2), 0)
    FROM tax_invoice_items
    WHERE tax_invoice_id = @invoice_id
);

SET @new_total := (
    SELECT COALESCE(ROUND(SUM(line_total), 2), 0)
    FROM tax_invoice_items
    WHERE tax_invoice_id = @invoice_id
);

SET @remaining_visit_note := (
    SELECT GROUP_CONCAT(id ORDER BY id SEPARATOR ', #')
    FROM appointments
    WHERE visit_id = @visit_id
      AND status = 'completed'
);

UPDATE tax_invoices
SET subtotal = @new_subtotal,
    vat_amount = @new_vat,
    total = @new_total,
    notes = CASE
        WHEN @remaining_visit_note IS NULL OR @remaining_visit_note = ''
            THEN notes
        ELSE CONCAT('Created from visit appointments #', @remaining_visit_note)
    END,
    updated_at = NOW()
WHERE id = @invoice_id;

-- This invoice currently has one cash payment. If the server has exactly one
-- payment row, keep it matched to the corrected invoice total.
SET @payment_count := (
    SELECT COUNT(*)
    FROM invoice_payments
    WHERE tax_invoice_id = @invoice_id
);

UPDATE invoice_payments
SET amount = @new_total,
    updated_at = NOW()
WHERE tax_invoice_id = @invoice_id
  AND @payment_count = 1;

COMMIT;

-- Verification: after running, RCT00314 should have no Classic Pedicure rows.
-- Expected final invoice total if only Classic Manicure remains: 89.25.
SELECT id, invoice_number, subtotal, vat_amount, total, notes
FROM tax_invoices
WHERE invoice_number IN ('RCT00314', 'RCT00318');

SELECT tii.id,
       ti.invoice_number,
       tii.description,
       tii.quantity,
       tii.unit_price,
       tii.discount_amount,
       tii.line_subtotal,
       tii.line_tax,
       tii.line_total,
       tii.staff_profile_id
FROM tax_invoice_items tii
JOIN tax_invoices ti ON ti.id = tii.tax_invoice_id
WHERE ti.invoice_number = 'RCT00314'
ORDER BY tii.id;

SELECT id, status, service_id, staff_profile_id, cancellation_reason
FROM appointments
WHERE visit_id = @visit_id
ORDER BY id;

SELECT id, tax_invoice_id, amount, method
FROM invoice_payments
WHERE tax_invoice_id = @invoice_id;
