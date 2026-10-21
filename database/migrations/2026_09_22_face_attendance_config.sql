-- Migration: Add face_attendance_config and attendance_settings columns
-- Apply to ccc_database_test
USE ccc_database_test;

CREATE TABLE IF NOT EXISTS `face_attendance_config` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `face_attendance_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_attendance_shadow_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `face_attendance_live_write` TINYINT(1) NOT NULL DEFAULT 1,
  `face_enrollment_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_punch_out_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_enrollment_uncalibrated_test_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `face_attendance_require_https` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` INT NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `face_attendance_config` (`id`, `face_attendance_enabled`, `face_attendance_shadow_mode`, `face_attendance_live_write`, `face_enrollment_enabled`, `face_punch_out_enabled`, `face_enrollment_uncalibrated_test_mode`, `face_attendance_require_https`)
VALUES (1, 1, 0, 1, 1, 1, 0, 1)
ON DUPLICATE KEY UPDATE
  face_attendance_enabled = VALUES(face_attendance_enabled),
  face_attendance_shadow_mode = VALUES(face_attendance_shadow_mode),
  face_attendance_live_write = VALUES(face_attendance_live_write),
  face_enrollment_enabled = VALUES(face_enrollment_enabled),
  face_punch_out_enabled = VALUES(face_punch_out_enabled),
  face_enrollment_uncalibrated_test_mode = VALUES(face_enrollment_uncalibrated_test_mode),
  face_attendance_require_https = VALUES(face_attendance_require_https);

-- Apply to ccc_database
USE ccc_database;

CREATE TABLE IF NOT EXISTS `face_attendance_config` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `face_attendance_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_attendance_shadow_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `face_attendance_live_write` TINYINT(1) NOT NULL DEFAULT 1,
  `face_enrollment_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_punch_out_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_enrollment_uncalibrated_test_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `face_attendance_require_https` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` INT NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `face_attendance_config` (`id`, `face_attendance_enabled`, `face_attendance_shadow_mode`, `face_attendance_live_write`, `face_enrollment_enabled`, `face_punch_out_enabled`, `face_enrollment_uncalibrated_test_mode`, `face_attendance_require_https`)
VALUES (1, 1, 0, 1, 1, 1, 0, 1)
ON DUPLICATE KEY UPDATE
  face_attendance_enabled = VALUES(face_attendance_enabled),
  face_attendance_shadow_mode = VALUES(face_attendance_shadow_mode),
  face_attendance_live_write = VALUES(face_attendance_live_write),
  face_enrollment_enabled = VALUES(face_enrollment_enabled),
  face_punch_out_enabled = VALUES(face_punch_out_enabled),
  face_enrollment_uncalibrated_test_mode = VALUES(face_enrollment_uncalibrated_test_mode),
  face_attendance_require_https = VALUES(face_attendance_require_https);

-- Apply to ccc_central
USE ccc_central;

CREATE TABLE IF NOT EXISTS `face_attendance_config` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `face_attendance_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_attendance_shadow_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `face_attendance_live_write` TINYINT(1) NOT NULL DEFAULT 1,
  `face_enrollment_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_punch_out_enabled` TINYINT(1) NOT NULL DEFAULT 1,
  `face_enrollment_uncalibrated_test_mode` TINYINT(1) NOT NULL DEFAULT 0,
  `face_attendance_require_https` TINYINT(1) NOT NULL DEFAULT 1,
  `updated_by` INT NOT NULL DEFAULT 1,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO `face_attendance_config` (`id`, `face_attendance_enabled`, `face_attendance_shadow_mode`, `face_attendance_live_write`, `face_enrollment_enabled`, `face_punch_out_enabled`, `face_enrollment_uncalibrated_test_mode`, `face_attendance_require_https`)
VALUES (1, 1, 0, 1, 1, 1, 0, 1)
ON DUPLICATE KEY UPDATE
  face_attendance_enabled = VALUES(face_attendance_enabled),
  face_attendance_shadow_mode = VALUES(face_attendance_shadow_mode),
  face_attendance_live_write = VALUES(face_attendance_live_write),
  face_enrollment_enabled = VALUES(face_enrollment_enabled),
  face_punch_out_enabled = VALUES(face_punch_out_enabled),
  face_enrollment_uncalibrated_test_mode = VALUES(face_enrollment_uncalibrated_test_mode),
  face_attendance_require_https = VALUES(face_attendance_require_https);
