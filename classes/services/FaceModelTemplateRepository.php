<?php
declare(strict_types=1);
require_once __DIR__ . '/FaceEnrollmentService.php';

/** Future challenger templates: encrypted biometric records, never experiment data. */
class FaceModelTemplateRepository
{
    private $conn;
    private $cipher;
    public function __construct(mysqli $conn, FaceEmbeddingCipher $cipher) { $this->conn = $conn; $this->cipher = $cipher; }
    public function store(int $memberId, string $modelName, string $modelVersion, string $preprocessingVersion, array $embedding): int
    {
        if ($memberId < 1 || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $modelName) || !preg_match('/^[A-Za-z0-9._:-]{1,100}$/', $modelVersion)) throw new InvalidArgumentException('Invalid model template metadata');
        foreach ($embedding as $value) if (!is_numeric($value) || !is_finite((float)$value)) throw new InvalidArgumentException('Invalid model template vector');
        $stmt = $this->conn->prepare('SELECT COALESCE(MAX(template_version),0)+1 v FROM face_model_template WHERE member_id=? AND model_name=? AND model_version=?');
        $stmt->bind_param('iss', $memberId, $modelName, $modelVersion); $stmt->execute(); $version = (int)$stmt->get_result()->fetch_assoc()['v']; $stmt->close();
        $encrypted = $this->cipher->encrypt($embedding); $keyId = $this->cipher->keyId();
        $insert = $this->conn->prepare("INSERT INTO face_model_template(member_id,model_name,model_version,template_version,encryption_key_id,encrypted_template,preprocessing_version,status) VALUES(?,?,?,?,?,?,?,'ACTIVE')");
        $insert->bind_param('ississs', $memberId, $modelName, $modelVersion, $version, $keyId, $encrypted, $preprocessingVersion);
        if (!$insert->execute()) throw new RuntimeException('Unable to store challenger template');
        $id = (int)$insert->insert_id; $insert->close(); return $id;
    }
}
