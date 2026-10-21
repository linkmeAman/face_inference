-- Additive only; does not activate or alter existing templates/attendance.
CREATE TABLE IF NOT EXISTS face_web_enrollment (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 branch_id INT NOT NULL, member_id INT NOT NULL, actor_id INT NOT NULL,
 state VARCHAR(30) NOT NULL DEFAULT 'PENDING',
 encrypted_template MEDIUMBLOB NOT NULL, encryption_key_id VARCHAR(100) NOT NULL,
 model_version VARCHAR(100) NOT NULL, preprocessing_version VARCHAR(100) NOT NULL,
 calibration_id BIGINT UNSIGNED NOT NULL, capture_session VARCHAR(80) NOT NULL,
 active_template_id INT NULL, conflict_member_id INT NULL,
 decision_reason VARCHAR(100) NOT NULL DEFAULT '', policy_sha256 CHAR(64) NULL,
 consented_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL, activated_at DATETIME NULL,
 updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 UNIQUE KEY one_candidate(member_id), KEY lifecycle_scope(branch_id,state)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS face_web_evidence (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 calibration_id BIGINT UNSIGNED NOT NULL,
 branch_id INT NOT NULL, member_id INT NOT NULL, actor_id INT NOT NULL,
 capture_session VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 purpose VARCHAR(20) NOT NULL, encrypted_observations MEDIUMBLOB NOT NULL,
 evaluation_context JSON NULL,
 encryption_key_id VARCHAR(100) NOT NULL,
 created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY one_capture(actor_id,capture_session), UNIQUE KEY one_evidence(calibration_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
