-- Corrected SFace preprocessing creates a new, incompatible embedding space.
-- Apply through scripts/migrate_face_identity_v3.php, which performs the
-- required existence checks and defaults to dry-run mode.

ALTER TABLE `face_model_version`
  DROP INDEX `uq_face_model_checksum`,
  ADD UNIQUE KEY `uq_face_model_checksum_preprocessing` (`model_checksum`, `preprocessing_version`);

INSERT INTO `face_model_version`
  (`model_name`, `provider_id`, `provider_version`, `detector_version`, `recognizer_version`,
   `preprocessing_version`, `embedding_dimension`, `model_checksum`, `license_reference`,
   `policy_version`, `status`, `created_by`)
SELECT
  CONCAT(`model_name`, '-opencv-preprocess-v2'), `provider_id`, '2', `detector_version`, `recognizer_version`,
  'sface-opencv-aligncrop-rgb-raw-v2', `embedding_dimension`, `model_checksum`, `license_reference`,
  'face-policy-v3', 'PENDING_CALIBRATION', 0
FROM `face_model_version`
WHERE `id` = 1;

UPDATE `face_template`
SET `status` = 'REVOKED', `revoked_at` = CURRENT_TIMESTAMP
WHERE `model_version_id` = 1 AND `status` = 'ACTIVE';

UPDATE `face_identity_reference`
SET `status` = 'SUPERSEDED'
WHERE `model_version_id` = 1 AND `status` = 'ACTIVE';

UPDATE `face_model_version`
SET `status` = 'RETIRED', `retired_at` = CURRENT_TIMESTAMP
WHERE `id` = 1 AND `status` = 'ACTIVE';
