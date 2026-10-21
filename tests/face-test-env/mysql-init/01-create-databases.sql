CREATE DATABASE IF NOT EXISTS ccc_database_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS ccc_central_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'face_test'@'%' IDENTIFIED BY 'ccc_face_test_password';
GRANT ALL PRIVILEGES ON ccc_database_test.* TO 'face_test'@'%';
GRANT ALL PRIVILEGES ON ccc_central_test.* TO 'face_test'@'%';
FLUSH PRIVILEGES;
