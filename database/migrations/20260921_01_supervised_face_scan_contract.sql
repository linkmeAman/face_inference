ALTER TABLE `face_model_version`
  ADD COLUMN `provider_id` VARCHAR(100) NOT NULL DEFAULT 'local-onnx' AFTER `model_name`,
  ADD COLUMN `provider_version` VARCHAR(100) NOT NULL DEFAULT '1' AFTER `provider_id`,
  ADD KEY `idx_face_model_provider` (`provider_id`, `provider_version`, `status`);

ALTER TABLE `attendance_scan`
  ADD COLUMN `provider_id` VARCHAR(100) NOT NULL DEFAULT 'legacy' AFTER `roster_version`,
  ADD COLUMN `provider_version` VARCHAR(100) NOT NULL DEFAULT 'legacy' AFTER `provider_id`,
  ADD COLUMN `model_version_id` INT NULL AFTER `provider_version`,
  ADD COLUMN `calibration_policy_version` VARCHAR(100) NOT NULL DEFAULT 'unconfigured' AFTER `model_version_id`,
  ADD KEY `idx_attendance_scan_provider_model` (`provider_id`, `model_version_id`),
  ADD CONSTRAINT `fk_attendance_scan_model` FOREIGN KEY (`model_version_id`) REFERENCES `face_model_version` (`id`);
