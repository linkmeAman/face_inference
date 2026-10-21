-- ATT-DB-01 gated migration.
-- Run only after 20260911_01 reports no unresolved duplicate groups and all
-- legacy session_id values have been explicitly reviewed/backfilled.
-- This migration intentionally aborts while ambiguous legacy rows remain.

DROP PROCEDURE IF EXISTS `att_db01_add_attendance_summary_key`;

DELIMITER //
CREATE PROCEDURE `att_db01_add_attendance_summary_key`()
BEGIN
  DECLARE duplicate_groups INT DEFAULT 0;
  DECLARE null_session_rows INT DEFAULT 0;

  SELECT COUNT(*) INTO duplicate_groups
  FROM (
    SELECT `bid`, `batch_id`, `date`, `session_id`, `contact_id`, `type`
    FROM `attendance`
    WHERE `park` = 0
    GROUP BY `bid`, `batch_id`, `date`, `session_id`, `contact_id`, `type`
    HAVING COUNT(*) > 1
  ) AS duplicate_rows;

  SELECT COUNT(*) INTO null_session_rows
  FROM `attendance`
  WHERE `session_id` IS NULL;

  IF duplicate_groups > 0 OR null_session_rows > 0 THEN
    SIGNAL SQLSTATE '45000'
      SET MESSAGE_TEXT = 'ATT-DB-01 blocked: resolve duplicate or NULL session attendance rows first';
  END IF;

  ALTER TABLE `attendance`
    ADD UNIQUE KEY `uq_attendance_summary_business`
      (`bid`, `batch_id`, `date`, `session_id`, `contact_id`, `type`);
END//
DELIMITER ;

CALL `att_db01_add_attendance_summary_key`();
DROP PROCEDURE IF EXISTS `att_db01_add_attendance_summary_key`;
