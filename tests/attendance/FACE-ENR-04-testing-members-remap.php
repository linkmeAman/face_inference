<?php

declare(strict_types=1);

/**
 * FACE-ENR-04: Testing members 616-620 remapping and separation contract.
 *
 * Verifies:
 * 1. The 15 historical contaminated templates are preserved with status 'REVOKED'.
 * 2. Members 616-620 each have at least one valid 'ACTIVE' template.
 * 3. Pairwise cosine similarity among all active centroids is strictly < 0.75
 *    (below the collision threshold), preventing false identity collisions.
 */

$root = dirname(__DIR__, 2);
require_once $root . '/classes/services/FaceEnrollmentService.php';
require_once $root . '/classes/dbconnection.class.php';

$envFile = $root . '/.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || strpos($line, '#') === 0 || strpos($line, '=') === false) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim($v);
        if (getenv($k) === false) {
            putenv("{$k}={$v}");
        }
    }
}

$dbHost = getenv('ATTENDANCE_TEST_DB_HOST') ?: '127.0.0.1';
$dbUser = getenv('ATTENDANCE_TEST_DB_USER') ?: 'nahid';
$dbPassword = getenv('ATTENDANCE_TEST_DB_PASSWORD') ?: '';
$dbName = getenv('ATTENDANCE_TEST_DB') ?: 'ccc_database';

$branchId = (int)(getenv('FACE_TEST_BRANCH_ID') ?: 2);
$key = getenv('FACE_EMBEDDING_KEY') ?: '';
$keyId = getenv('FACE_EMBEDDING_KEY_ID') ?: '';
$collisionThreshold = (float)(getenv('FACE_ENROLLMENT_DUPLICATE_THRESHOLD') ?: '0.75');

$failures = [];
$assertions = 0;

if ($key === '' || $keyId === '') {
    $failures[] = 'FACE_EMBEDDING_KEY or FACE_EMBEDDING_KEY_ID environment variable is missing';
}

$db = @new mysqli($dbHost, $dbUser, $dbPassword, $dbName);
if ($db->connect_errno) {
    fwrite(STDERR, "BLOCKED FACE-ENR-04: Cannot connect to MySQL at {$dbHost}: {$db->connect_error}\n");
    exit(2);
}
$db->set_charset('utf8mb4');

$targetMembers = [616, 617, 618, 619, 620];
$escapedMemberIds = implode(',', $targetMembers);

// 1. Check historical revoked contaminated templates are retained
$revokedResult = $db->query(
    "SELECT COUNT(*) AS cnt FROM face_template WHERE member_id IN ({$escapedMemberIds}) AND status = 'REVOKED'"
);
$revokedRow = $revokedResult ? $revokedResult->fetch_assoc() : null;
$revokedCount = (int)($revokedRow['cnt'] ?? 0);
$assertions++;
if ($revokedCount < 15) {
    $failures[] = "expected at least 15 historical REVOKED templates for members 616-620, found {$revokedCount}";
}

// 2. Check active templates exist for each member
$cipher = new FaceEmbeddingCipher($key, $keyId);
$activeResult = $db->query(
    "SELECT ft.member_id, ft.model_version_id, ft.embedding_dimension, ft.encrypted_embedding " .
    "FROM face_template ft " .
    "INNER JOIN member m ON m.id = ft.member_id " .
    "WHERE ft.status = 'ACTIVE' AND ft.member_id IN ({$escapedMemberIds}) AND m.bid = '{$branchId}' " .
    "ORDER BY ft.member_id, ft.id"
);

$memberTemplates = [];
if ($activeResult) {
    while ($row = $activeResult->fetch_assoc()) {
        $mId = (int)$row['member_id'];
        try {
            $embedding = $cipher->decrypt((string)$row['encrypted_embedding']);
        } catch (Throwable $e) {
            $failures[] = "could not decrypt active template for member {$mId}";
            continue;
        }
        if (count($embedding) !== (int)$row['embedding_dimension']) {
            $failures[] = "dimension mismatch for member {$mId} active template";
            continue;
        }
        $memberTemplates[$mId][] = $embedding;
    }
    $activeResult->free();
}

foreach ($targetMembers as $mId) {
    $assertions++;
    if (empty($memberTemplates[$mId])) {
        $failures[] = "member {$mId} has no ACTIVE face templates (requires remapping)";
    }
}

// 3. Pairwise cosine similarity among active centroids
function computeCentroid(array $embeddings): array
{
    $dim = count($embeddings[0]);
    $centroid = array_fill(0, $dim, 0.0);
    foreach ($embeddings as $emb) {
        foreach ($emb as $idx => $val) {
            $centroid[$idx] += (float)$val;
        }
    }
    $count = count($embeddings);
    foreach ($centroid as $idx => $val) {
        $centroid[$idx] = $val / $count;
    }
    return $centroid;
}

function computeCosine(array $a, array $b): float
{
    $dot = 0.0;
    $normA = 0.0;
    $normB = 0.0;
    foreach ($a as $i => $val) {
        $left = (float)$val;
        $right = (float)$b[$i];
        $dot += $left * $right;
        $normA += $left * $left;
        $normB += $right * $right;
    }
    $denom = sqrt($normA) * sqrt($normB);
    return $denom > 0.0 ? ($dot / $denom) : -1.0;
}

if (count($memberTemplates) === count($targetMembers)) {
    $centroids = [];
    foreach ($targetMembers as $mId) {
        $centroids[$mId] = computeCentroid($memberTemplates[$mId]);
    }

    $pairsChecked = 0;
    for ($i = 0; $i < count($targetMembers); $i++) {
        for ($j = $i + 1; $j < count($targetMembers); $j++) {
            $id1 = $targetMembers[$i];
            $id2 = $targetMembers[$j];
            $sim = computeCosine($centroids[$id1], $centroids[$id2]);
            $assertions++;
            $pairsChecked++;
            if ($sim >= $collisionThreshold) {
                $failures[] = sprintf(
                    "collision detected between members %d and %d: cosine similarity %.4f >= threshold %.2f",
                    $id1,
                    $id2,
                    $sim,
                    $collisionThreshold
                );
            }
        }
    }
}

$db->close();

if ($failures) {
    foreach ($failures as $f) {
        fwrite(STDERR, "FAIL FACE-ENR-04 {$f}\n");
    }
    exit(1);
}

echo "PASS FACE-ENR-04: All 5 members 616-620 have distinct active templates (pairwise similarity < {$collisionThreshold}), and {$revokedCount} historical revoked templates are retained.\n";
exit(0);
