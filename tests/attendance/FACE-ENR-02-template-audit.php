<?php

declare(strict_types=1);

/**
 * Read-only active-template collision audit.
 *
 * Prints member-level cosine scores only. Decrypted embeddings remain in
 * process memory and are never logged or written.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/classes/services/FaceEnrollmentService.php';

$dbHost = getenv('FACE_AUDIT_DB_HOST') ?: '127.0.0.1';
$dbName = getenv('FACE_AUDIT_DB') ?: '';
$dbUser = getenv('FACE_AUDIT_DB_USER') ?: '';
$dbPassword = getenv('FACE_AUDIT_DB_PASSWORD') ?: '';
$branchId = (int)(getenv('FACE_AUDIT_BRANCH_ID') ?: 0);
$key = getenv('FACE_EMBEDDING_KEY') ?: '';
$keyId = getenv('FACE_EMBEDDING_KEY_ID') ?: '';
$threshold = (float)(getenv('FACE_ENROLLMENT_DUPLICATE_THRESHOLD') ?: '0.75');
$templateStatus = strtoupper(getenv('FACE_AUDIT_TEMPLATE_STATUS') ?: 'ACTIVE');

if ($dbName === '' || $dbUser === '' || $branchId < 1 || $key === '' || $keyId === ''
    || !in_array($templateStatus, ['ACTIVE', 'REVOKED'], true)) {
    fwrite(STDERR, "BLOCKED FACE-ENR-02 audit: database, branch, and embedding-key environment values are required.\n");
    exit(2);
}

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$db = new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
$db->set_charset('utf8mb4');
$cipher = new FaceEmbeddingCipher($key, $keyId);
$result = $db->query(
    "SELECT ft.member_id, ft.model_version_id, ft.embedding_dimension, ft.encrypted_embedding " .
    "FROM face_template ft INNER JOIN member m ON m.id = ft.member_id " .
    "WHERE ft.status = '{$templateStatus}' AND m.bid = '{$branchId}' AND m.status = '1' AND m.park = '0' " .
    "ORDER BY ft.member_id, ft.id"
);

$members = [];
while ($row = $result->fetch_assoc()) {
    try {
        $embedding = $cipher->decrypt((string)$row['encrypted_embedding']);
    } catch (Throwable $exception) {
        fwrite(STDERR, "WARN FACE-ENR-02 audit: unreadable active template for member " . (int)$row['member_id'] . ".\n");
        continue;
    }
    if (count($embedding) !== (int)$row['embedding_dimension']) {
        continue;
    }
    $keyName = (int)$row['model_version_id'] . ':' . (int)$row['member_id'];
    if (!isset($members[$keyName])) {
        $members[$keyName] = [
            'member_id' => (int)$row['member_id'],
            'model_version_id' => (int)$row['model_version_id'],
            'embeddings' => [],
        ];
    }
    $members[$keyName]['embeddings'][] = $embedding;
}
$result->free();
$db->close();

function auditCentroid(array $embeddings): array
{
    $dimension = count($embeddings[0]);
    $centroid = array_fill(0, $dimension, 0.0);
    foreach ($embeddings as $embedding) {
        foreach ($embedding as $index => $value) {
            $centroid[$index] += (float)$value;
        }
    }
    foreach ($centroid as $index => $value) {
        $centroid[$index] = $value / count($embeddings);
    }
    return $centroid;
}

function auditCosine(array $first, array $second): float
{
    if (count($first) !== count($second) || !$first) {
        return -1.0;
    }
    $dot = 0.0;
    $firstNorm = 0.0;
    $secondNorm = 0.0;
    foreach ($first as $index => $value) {
        $left = (float)$value;
        $right = (float)$second[$index];
        $dot += $left * $right;
        $firstNorm += $left * $left;
        $secondNorm += $right * $right;
    }
    $denominator = sqrt($firstNorm) * sqrt($secondNorm);
    return $denominator > 0.0 ? $dot / $denominator : -1.0;
}

$groups = array_values($members);
$collisions = 0;
for ($left = 0; $left < count($groups); $left++) {
    for ($right = $left + 1; $right < count($groups); $right++) {
        if ($groups[$left]['model_version_id'] !== $groups[$right]['model_version_id']) {
            continue;
        }
        $centroidScore = auditCosine(
            auditCentroid($groups[$left]['embeddings']),
            auditCentroid($groups[$right]['embeddings'])
        );
        $templateScore = -1.0;
        foreach ($groups[$left]['embeddings'] as $leftEmbedding) {
            foreach ($groups[$right]['embeddings'] as $rightEmbedding) {
                $templateScore = max($templateScore, auditCosine($leftEmbedding, $rightEmbedding));
            }
        }
        $score = max($centroidScore, $templateScore);
        $collision = $score >= $threshold;
        if ($collision) {
            $collisions++;
        }
        printf(
            "member_a=%d member_b=%d model=%d centroid_cosine=%.7f max_template_cosine=%.7f collision=%s\n",
            $groups[$left]['member_id'],
            $groups[$right]['member_id'],
            $groups[$left]['model_version_id'],
            $centroidScore,
            $templateScore,
            $collision ? 'YES' : 'NO'
        );
    }
}

printf("FACE-ENR-02 audit: %d member groups, %d collision pairs at threshold %.4f.\n", count($groups), $collisions, $threshold);
exit($collisions > 0 ? 1 : 0);
