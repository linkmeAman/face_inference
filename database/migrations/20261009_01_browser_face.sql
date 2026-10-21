-- Additive only. Apply to the selected tenant after review/backup.
-- Requires existing face and attendance migrations. No existing data changed.
CREATE TABLE IF NOT EXISTS face_web_request (
  actor_id INT NOT NULL,
  request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  action VARCHAR(20) NOT NULL,
  payload_hash CHAR(64) CHARACTER SET ascii NOT NULL,
  response JSON NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (actor_id, request_key),
  KEY idx_face_web_rate (actor_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO face_model_version
 (model_name,provider_id,provider_version,detector_version,recognizer_version,
 preprocessing_version,embedding_dimension,model_checksum,license_reference,policy_version,status,created_by)
VALUES ('sface-int8bq-fb143eea','browser-onnx','1','yunet-int8-640','sface-int8bq',
 'yunet640-similarity5-rgb255-v1',128,
 'fb143eea07838aa532d1c95df5f69899974ea0140e1fba05e94204be13ed74ee',
 'services/face-inference/models/MODEL-LICENSES.md','browser-face-calibration-v1','PENDING_CALIBRATION',0)
ON DUPLICATE KEY UPDATE id=id;
