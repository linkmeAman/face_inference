<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/classes/dbconnection.class.php';
require_once dirname(__DIR__) . '/classes/session.class.php';
require_once dirname(__DIR__) . '/classes/services/FaceAttendanceConfig.php';

/** Position-1-only aggregate experiment health. It never reads score rows. */
class faceExperimentStatus extends dbconnection
{
    public function status(array $request): string
    {
        $session = new session();
        $dbName = $session->check_session($request);
        if (!$dbName || (int)($_SESSION['position_id'] ?? 0) !== 1) {
            return json_encode(['error' => 1, 'error_msg' => 'Position-1 administrator access is required', 'data' => []]);
        }
        $conn = $this->connect($dbName);
        try {
            $view = $conn->query("SHOW TABLES LIKE 'face_experiment_status'");
            if (!$view || !$view->num_rows) return json_encode(['error' => 0, 'error_msg' => '', 'data' => ['migration' => 'NOT_APPLIED'], 'message' => 'Experiment logger migration is not applied']);
            $status = $conn->query('SELECT total_rows,primary_rows,shadow_rows,expired_rows,latest_row_at,table_bytes,latest_export_at,latest_export_sha256 FROM face_experiment_status')->fetch_assoc() ?: [];
            $models = [];
            $result = $conn->query('SELECT source,model_name,model_version,row_count FROM face_experiment_status_by_model ORDER BY source,model_name,model_version');
            while ($result && ($row = $result->fetch_assoc())) $models[] = $row;
            $size = (int)($status['table_bytes'] ?? 0);
            $data = [
                'migration' => 'APPLIED', 'down_migration_available' => true,
                'experiment_config' => ['enabled' => FaceAttendanceConfig::getEnv('FACE_EXPERIMENT_LOGGING_ENABLED') === '1', 'opaque_id_version' => (int)(FaceAttendanceConfig::getEnv('FACE_EXPERIMENT_OPAQUE_ID_VERSION') ?: 1)],
                'enabled_variants' => ['SFace' => true, 'EdgeFace-XS-FP32' => false, 'EdgeFace-XS-FP16' => false, 'EdgeFace-XS-INT8' => false, 'GhostFaceNetV1-1' => false, 'MiniFAS' => false],
                'counts_by_source_model' => $models, 'queue_failures' => 0, 'purge_export' => ['at' => $status['latest_export_at'] ?? null, 'sha256' => $status['latest_export_sha256'] ?? null],
                'table_size_bytes' => $size, 'table_size_limit_bytes' => 10 * 1024 * 1024 * 1024, 'table_size_warning' => $size >= 8 * 1024 * 1024 * 1024, 'shadow_logging_stopped' => $size >= (int)(9.5 * 1024 * 1024 * 1024),
                'latest_test_status' => 'See services/face-inference/tests/test_face_experiment_logger.py',
            ];
            return json_encode(['error' => 0, 'error_msg' => '', 'data' => $data, 'message' => 'Experiment status']);
        } finally { $conn->close(); }
    }
}
