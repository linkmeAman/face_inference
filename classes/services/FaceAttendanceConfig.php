<?php

class FaceAttendanceConfig
{
    const DEFAULTS = [
        'FACE_ATTENDANCE_ENABLED'                => true,
        'FACE_TEACHER_SELECTION_ENABLED'        => true,
        'FACE_ATTENDANCE_SHADOW_MODE'            => true,
        'FACE_ATTENDANCE_LIVE_WRITE'             => false,
        'FACE_ENROLLMENT_ENABLED'               => true,
        'FACE_GUIDED_ADAPTIVE_V2_ENABLED'       => false,
        'FACE_PUNCH_OUT_ENABLED'                => false,
        'FACE_ENROLLMENT_UNCALIBRATED_TEST_MODE' => false,
        'FACE_RECALIBRATION_ENABLED'             => false,
        'FACE_ATTENDANCE_REQUIRE_HTTPS'          => true,
    ];

    const COLUMN_MAP = [
        'FACE_ATTENDANCE_ENABLED'                => 'face_attendance_enabled',
        'FACE_ATTENDANCE_SHADOW_MODE'            => 'face_attendance_shadow_mode',
        'FACE_ATTENDANCE_LIVE_WRITE'             => 'face_attendance_live_write',
        'FACE_ENROLLMENT_ENABLED'               => 'face_enrollment_enabled',
        'FACE_PUNCH_OUT_ENABLED'                => 'face_punch_out_enabled',
        'FACE_ENROLLMENT_UNCALIBRATED_TEST_MODE' => 'face_enrollment_uncalibrated_test_mode',
        'FACE_RECALIBRATION_ENABLED'             => 'face_recalibration_enabled',
        'FACE_ATTENDANCE_REQUIRE_HTTPS'          => 'face_attendance_require_https',
    ];

    /**
     * Get a MySQL connection if none provided.
     */
    public static function getConnection(?mysqli $conn = null): ?mysqli
    {
        if ($conn instanceof mysqli && !$conn->connect_errno) {
            return $conn;
        }
        $dbName = !empty($_SESSION['db_name']) ? $_SESSION['db_name'] : 'ccc_database';
        $dbsToTry = array_unique([$dbName, 'ccc_database_test', 'ccc_database', 'ccc_central']);
        foreach ($dbsToTry as $db) {
            $mysqli = @new mysqli("localhost", "nahid", "", $db);
            if (!$mysqli->connect_errno) {
                return $mysqli;
            }
        }
        return null;
    }

    /**
     * Load all face configuration flags from DB with fallback to getenv.
     */
    public static function get(?mysqli $conn = null, int $branchId = 0): array
    {
        $result = self::DEFAULTS;

        $db = self::getConnection($conn);
        $loadedFromDb = false;

        if ($db) {
            // 1. Try branch-specific settings from attendance_settings if branchId > 0
            if ($branchId > 0) {
                $checkTable = $db->query("SHOW TABLES LIKE 'attendance_settings'");
                if ($checkTable && $checkTable->num_rows > 0) {
                    $cols = implode(', ', array_values(self::COLUMN_MAP));
                    $stmt = $db->query("SELECT {$cols} FROM `attendance_settings` WHERE `bid` = {$branchId} LIMIT 1");
                    if ($stmt && ($row = $stmt->fetch_assoc())) {
                        foreach (self::COLUMN_MAP as $envKey => $colName) {
                            if (isset($row[$colName])) {
                                $result[$envKey] = (bool)(int)$row[$colName];
                                $result[$colName] = $result[$envKey];
                            }
                        }
                        $loadedFromDb = true;
                    }
                }
            }

            // 2. Try global face_attendance_config table
            if (!$loadedFromDb) {
                $checkTable = $db->query("SHOW TABLES LIKE 'face_attendance_config'");
                if ($checkTable && $checkTable->num_rows > 0) {
                    $cols = implode(', ', array_values(self::COLUMN_MAP));
                    $stmt = $db->query("SELECT {$cols} FROM `face_attendance_config` WHERE `id` = 1 LIMIT 1");
                    if ($stmt && ($row = $stmt->fetch_assoc())) {
                        foreach (self::COLUMN_MAP as $envKey => $colName) {
                            if (isset($row[$colName])) {
                                $result[$envKey] = (bool)(int)$row[$colName];
                                $result[$colName] = $result[$envKey];
                            }
                        }
                        $loadedFromDb = true;
                    }
                }
            }

            // 3. If neither returned rows, try attendance_settings without branch limit (first row)
            if (!$loadedFromDb) {
                $checkTable = $db->query("SHOW TABLES LIKE 'attendance_settings'");
                if ($checkTable && $checkTable->num_rows > 0) {
                    $cols = implode(', ', array_values(self::COLUMN_MAP));
                    $stmt = $db->query("SELECT {$cols} FROM `attendance_settings` LIMIT 1");
                    if ($stmt && ($row = $stmt->fetch_assoc())) {
                        foreach (self::COLUMN_MAP as $envKey => $colName) {
                            if (isset($row[$colName])) {
                                $result[$envKey] = (bool)(int)$row[$colName];
                                $result[$colName] = $result[$envKey];
                            }
                        }
                        $loadedFromDb = true;
                    }
                }
            }

            $checkSel = $db->query("SHOW TABLES LIKE 'face_selection_control'");
            if ($checkSel && $checkSel->num_rows > 0) {
                $selRow = $db->query("SELECT enabled FROM face_selection_control WHERE id = 1 LIMIT 1");
                if ($selRow && ($r = $selRow->fetch_assoc())) {
                    $result['FACE_TEACHER_SELECTION_ENABLED'] = (bool)(int)$r['enabled'];
                }
            }

            // Close connection only if we created it locally
            if (!$conn && $db) {
                $db->close();
            }
        }

        // Database values are the legacy fallback. Explicit .env values always
        // win, including when branch or global database settings are present.
        foreach (self::COLUMN_MAP as $envKey => $colName) {
            $envVal = self::getEnv($envKey);
            if ($envVal !== '') $result[$envKey] = filter_var($envVal, FILTER_VALIDATE_BOOLEAN);
            $result[$colName] = $result[$envKey];
        }

        $envSel = self::getEnv('FACE_TEACHER_SELECTION_ENABLED');
        if ($envSel !== '') $result['FACE_TEACHER_SELECTION_ENABLED'] = filter_var($envSel, FILTER_VALIDATE_BOOLEAN);
        $result['face_teacher_selection_enabled'] = $result['FACE_TEACHER_SELECTION_ENABLED'] ?? true;
        // Pilot flag is runtime-only; no production schema change is needed.
        $result['FACE_GUIDED_ADAPTIVE_V2_ENABLED'] = filter_var(
            self::getEnv('FACE_GUIDED_ADAPTIVE_V2_ENABLED'), FILTER_VALIDATE_BOOLEAN);

        return $result;
    }

    /**
     * Get a single flag boolean value.
     */
    public static function getFlag(string $key, ?mysqli $conn = null, int $branchId = 0): bool
    {
        $all = self::get($conn, $branchId);
        $upper = strtoupper($key);
        if (isset($all[$upper])) {
            return (bool)$all[$upper];
        }
        $lower = strtolower($key);
        if (isset($all[$lower])) {
            return (bool)$all[$lower];
        }
        return false;
    }

    /**
     * Save flags to all relevant databases and tables.
     */
    public static function set(array $flags, int $userId = 1): bool
    {
        $dbs = ['ccc_database_test', 'ccc_database', 'ccc_central'];
        $clean = [];
        foreach (self::COLUMN_MAP as $envKey => $colName) {
            $val = null;
            if (isset($flags[$envKey])) {
                $val = ($flags[$envKey] === '1' || $flags[$envKey] === 1 || $flags[$envKey] === true || $flags[$envKey] === 'true') ? 1 : 0;
            } elseif (isset($flags[$colName])) {
                $val = ($flags[$colName] === '1' || $flags[$colName] === 1 || $flags[$colName] === true || $flags[$colName] === 'true') ? 1 : 0;
            } else {
                $val = !empty(self::DEFAULTS[$envKey]) ? 1 : 0;
            }
            $clean[$colName] = $val;
            $clean[$envKey] = $val;
        }

        $allOk = true;

        foreach ($dbs as $dbName) {
            $mysqli = @new mysqli("localhost", "nahid", "", $dbName);
            if ($mysqli->connect_errno) {
                continue;
            }

            // 1. Update/Insert in face_attendance_config
            $colList = [];
            $valList = [];
            $updateList = [];
            foreach (self::COLUMN_MAP as $envKey => $colName) {
                $v = (int)$clean[$colName];
                $colList[] = "`$colName`";
                $valList[] = $v;
                $updateList[] = "`$colName` = $v";
            }
            $sql = "INSERT INTO `face_attendance_config` (`id`, " . implode(', ', $colList) . ", `updated_by`)
                    VALUES (1, " . implode(', ', $valList) . ", {$userId})
                    ON DUPLICATE KEY UPDATE " . implode(', ', $updateList) . ", `updated_by` = {$userId}";
            if (!$mysqli->query($sql)) {
                $allOk = false;
            }

            // 2. Also update attendance_settings if table exists
            $checkTable = $mysqli->query("SHOW TABLES LIKE 'attendance_settings'");
            if ($checkTable && $checkTable->num_rows > 0) {
                $attUpdates = [];
                foreach (self::COLUMN_MAP as $envKey => $colName) {
                    $v = (int)$clean[$colName];
                    $attUpdates[] = "`$colName` = $v";
                }
                $mysqli->query("UPDATE `attendance_settings` SET " . implode(', ', $attUpdates));
            }

            // 3. Update face_selection_control if passed
            if (isset($flags['FACE_TEACHER_SELECTION_ENABLED']) || isset($flags['face_teacher_selection_enabled'])) {
                $selVal = (!empty($flags['FACE_TEACHER_SELECTION_ENABLED']) || !empty($flags['face_teacher_selection_enabled'])) ? 1 : 0;
                $checkSel = $mysqli->query("SHOW TABLES LIKE 'face_selection_control'");
                if ($checkSel && $checkSel->num_rows > 0) {
                    $mysqli->query("INSERT INTO `face_selection_control` (`id`, `enabled`) VALUES (1, {$selVal}) ON DUPLICATE KEY UPDATE `enabled` = {$selVal}");
                }
            }

            $mysqli->close();
        }

        return $allOk;
    }

    /**
     * Get environment variable with fallback to .env file if FastCGI param is not present.
     */
    public static function getEnv(string $key): string
    {
        $val = getenv($key);
        if ($val !== false && $val !== '') {
            return (string)$val;
        }
        if (isset($_SERVER[$key]) && (string)$_SERVER[$key] !== '') {
            return (string)$_SERVER[$key];
        }
        static $envFileVars = null;
        if ($envFileVars === null) {
            $envFileVars = [];
            $envPath = dirname(dirname(__DIR__)) . '/.env';
            if (file_exists($envPath) && is_readable($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $k = trim($parts[0]);
                        $v = trim($parts[1], " \t\n\r\0\x0B\"'");
                        $envFileVars[$k] = $v;
                    }
                }
            }
        }
        return $envFileVars[$key] ?? '';
    }
}
