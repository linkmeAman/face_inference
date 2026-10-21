-- ATT-DB-01 preflight. Read-only: do not run this file through a write-only
-- migration runner. Save the result with the deployment record.

SELECT
  contact_id,
  batch_id,
  date AS attendance_date,
  session_id,
  type AS person_type,
  COUNT(*) AS duplicate_count,
  GROUP_CONCAT(id ORDER BY id) AS attendance_ids
FROM attendance
WHERE park = 0
GROUP BY contact_id, batch_id, date, session_id, type
HAVING COUNT(*) > 1
ORDER BY attendance_date, batch_id, contact_id;

SELECT COUNT(*) AS session_zero_rows
FROM attendance
WHERE session_id = 0;

SELECT COUNT(*) AS null_session_rows
FROM attendance
WHERE session_id IS NULL;

SELECT
  contact_id,
  batch_id,
  date AS attendance_date,
  session_id,
  type AS person_type,
  COUNT(*) AS duplicate_count,
  GROUP_CONCAT(id ORDER BY id) AS attendance_ids
FROM attendance
GROUP BY contact_id, batch_id, date, session_id, type
HAVING COUNT(*) > 1
ORDER BY attendance_date, batch_id, contact_id;
