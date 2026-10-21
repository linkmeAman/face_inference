-- Migration: High-Precision Face Enrollment and Attendance Assurance
-- Authoritative policy: face-policy-v2
-- Date: 2026-09-25

-- 1. Canonical Biometric Subject (Tenant-wide per contact_id)
CREATE TABLE IF NOT EXISTS `biometric_subject` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `contact_id` INT NOT NULL,
  `status` VARCHAR(32) NOT NULL DEFAULT 'ACTIVE',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_biometric_subject_contact` (`contact_id`),
  KEY `idx_biometric_subject_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Verified Identity Reference (Authorized portrait & embedding onboarding)
CREATE TABLE IF NOT EXISTS `face_identity_reference` (
  `id` INT NOT NULL AUTO_INCREMENT,
  `subject_id` INT NOT NULL,
  `encrypted_portrait` MEDIUMBLOB NULL,
  `encrypted_embedding` MEDIUMBLOB NOT NULL,
  `encryption_key_id` VARCHAR(100) NOT NULL,
  `embedding_dimension` INT NOT NULL DEFAULT 128,
  `source` VARCHAR(50) NOT NULL DEFAULT 'STAFF_ONBOARDING',
  `quality_evidence` JSON NULL,
  `model_version_id` INT NOT NULL,
  `policy_version` VARCHAR(100) NOT NULL DEFAULT 'face-policy-v2',
  `verifier_actor_id` INT NOT NULL,
  `verified_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `status` VARCHAR(20) NOT NULL DEFAULT 'ACTIVE',
  PRIMARY KEY (`id`),
  KEY `idx_face_identity_reference_subject` (`subject_id`, `status`),
  KEY `idx_face_identity_reference_model` (`model_version_id`),
  CONSTRAINT `fk_face_identity_reference_subject` FOREIGN KEY (`subject_id`) REFERENCES `biometric_subject` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. Face Enrollment Attempt (Two-burst state machine & audit)
CREATE TABLE IF NOT EXISTS `face_enrollment_attempt` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `client_attempt_id` VARCHAR(191) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `subject_id` INT NOT NULL,
  `member_id` INT NOT NULL,
  `branch_id` INT NOT NULL,
  `reference_id` INT NOT NULL,
  `burst_a_count` INT NOT NULL DEFAULT 0,
  `burst_b_count` INT NOT NULL DEFAULT 0,
  `two_burst_state` VARCHAR(32) NOT NULL DEFAULT 'INIT',
  `decision` VARCHAR(32) NOT NULL,
  `reason_code` VARCHAR(64) NOT NULL,
  `threshold_snapshot` JSON NULL,
  `duplicate_candidate_result` JSON NULL,
  `actor_employee_id` INT NOT NULL,
  `device_metadata` JSON NULL,
  `venue` VARCHAR(100) NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `completed_at` DATETIME NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_face_enrollment_attempt_client` (`client_attempt_id`),
  KEY `idx_face_enrollment_attempt_subject` (`subject_id`),
  KEY `idx_face_enrollment_attempt_member` (`member_id`),
  CONSTRAINT `fk_face_enrollment_attempt_subject` FOREIGN KEY (`subject_id`) REFERENCES `biometric_subject` (`id`),
  CONSTRAINT `fk_face_enrollment_attempt_member` FOREIGN KEY (`member_id`) REFERENCES `member` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. Face Capture Evidence (Per-frame numerical quality/PAD metrics, never image bytes)
CREATE TABLE IF NOT EXISTS `face_capture_evidence` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `context_type` VARCHAR(32) NOT NULL,
  `context_id` BIGINT UNSIGNED NOT NULL,
  `frame_index` INT NOT NULL,
  `frame_hash` CHAR(64) NOT NULL,
  `face_count` INT NOT NULL DEFAULT 0,
  `detector_confidence` DECIMAL(8,4) NULL,
  `box_x` DECIMAL(8,2) NULL,
  `box_y` DECIMAL(8,2) NULL,
  `box_w` DECIMAL(8,2) NULL,
  `box_h` DECIMAL(8,2) NULL,
  `inter_eye_pixels` DECIMAL(8,2) NULL,
  `sharpness` DECIMAL(10,3) NULL,
  `exposure` DECIMAL(10,3) NULL,
  `contrast` DECIMAL(10,3) NULL,
  `clipped_dark_pct` DECIMAL(6,3) NULL,
  `clipped_bright_pct` DECIMAL(6,3) NULL,
  `illumination_balance` DECIMAL(6,3) NULL,
  `yaw` DECIMAL(6,3) NULL,
  `pitch` DECIMAL(6,3) NULL,
  `roll` DECIMAL(6,3) NULL,
  `centering_offset` DECIMAL(6,3) NULL,
  `pad_score` DECIMAL(8,4) NULL,
  `pad_state` VARCHAR(20) NULL,
  `accepted` TINYINT(1) NOT NULL DEFAULT 0,
  `reasons` JSON NULL,
  `quality_policy_version` VARCHAR(100) NOT NULL DEFAULT 'face-policy-v2',
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_face_capture_evidence_context` (`context_type`, `context_id`),
  KEY `idx_face_capture_evidence_hash` (`frame_hash`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. Alter face_template to link to biometric_subject, enrollment_attempt, and reference
-- Run idempotently via PHP migration runner
