-- Additive tenant schema. No existing enrollment or attendance is modified.
CREATE TABLE IF NOT EXISTS face_web_calibration (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 branch_id INT NOT NULL, member_id INT NOT NULL, actor_id INT NOT NULL,
 encrypted_embedding MEDIUMBLOB NOT NULL, encryption_key_id VARCHAR(100) NOT NULL,
 model_version VARCHAR(100) NOT NULL, preprocessing_version VARCHAR(100) NOT NULL,
 visit VARCHAR(100) NOT NULL, device VARCHAR(100) NOT NULL, lighting VARCHAR(100) NOT NULL,
 dataset_split VARCHAR(20) NOT NULL, consent_notice VARCHAR(50) NOT NULL,
 consented_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 expires_at DATETIME NOT NULL, deleted_at DATETIME NULL,
 KEY collection_scope(branch_id,deleted_at,expires_at), KEY person_scope(member_id,branch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS face_web_migration (
 id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
 branch_id INT NOT NULL, member_id INT NOT NULL, actor_id INT NOT NULL,
 replacement_template_id INT NOT NULL, legacy_snapshot JSON NOT NULL,
 request_key VARCHAR(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
 approved_by VARCHAR(100) NOT NULL, rolled_back_at DATETIME NULL, rolled_back_by VARCHAR(100) NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
 UNIQUE KEY migration_request(actor_id,request_key), KEY migration_person(branch_id,member_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
