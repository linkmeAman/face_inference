<?php
require_once dirname(__DIR__, 2) . '/classes/services/FaceAttendanceConfig.php';
require_once dirname(__DIR__, 2) . '/classes/services/FaceEnrollmentService.php';

putenv('FACE_EMBEDDING_KEY=adaptive-evidence-test-key');
$service = (new ReflectionClass(FaceEnrollmentService::class))->newInstanceWithoutConstructor();
$keyMethod = new ReflectionMethod(FaceEnrollmentService::class, 'candidateSigningKey');
$verify = new ReflectionMethod(FaceEnrollmentService::class, 'verifyAdaptiveCaptureEvidence');
$frames = array_map(fn($i) => 'jpeg-bytes-' . $i, range(0, 7));
$contexts = array_map(fn($i) => ['frame_width'=>640,'frame_height'=>480,'slot'=>$i], range(0, 7));
$times = [1700000000000,1700000000400,1700000000800,1700000001200,1700000001600,
    1700000002900,1700000003300,1700000003700];
$captureToken = 'test-capture-token';
$key = $keyMethod->invoke($service, $captureToken);
$tokens = [];
foreach ($frames as $i => $frame) {
    $time = (string)$times[$i];
    $contextHash = hash('sha256', json_encode($contexts[$i], JSON_UNESCAPED_SLASHES));
    $tokens[] = $time . ':' . hash_hmac('sha256', $i . ':' . hash('sha256', $frame) . ':' . $contextHash . ':' . $time, $key);
}
$departure = [];
foreach ([8 => 1700000001800, 9 => 1700000002550] as $slot => $time) {
    $hash = hash('sha256', 'empty-' . $slot);
    $departure[] = ['timestamp_ms'=>$time,'sha256'=>$hash,
        'token'=>hash_hmac('sha256', $slot . ':' . $hash . ':' . $time, $key)];
}
$command = ['capture_session_token'=>$captureToken,'candidate_tokens'=>$tokens,'departure_tokens'=>$departure,
    'capture_contexts_a'=>array_slice($contexts,0,5),'capture_contexts_b'=>array_slice($contexts,5)];
$check = fn($cmd, $f = null) => $verify->invoke($service, $cmd, array_slice($f ?? $frames, 0, 5), array_slice($f ?? $frames, 5));
if ($check($command) !== null) throw new RuntimeException('valid evidence rejected');
$continuous = $command;
unset($continuous['departure_tokens']);
if ($check($continuous) !== null) throw new RuntimeException('continuous capture with signed 750ms gap rejected');
$tooSoon = $continuous;
$early = (string)($times[4] + 749);
$contextHash = hash('sha256', json_encode($contexts[5], JSON_UNESCAPED_SLASHES));
$tooSoon['candidate_tokens'][5] = $early . ':' . hash_hmac('sha256',
    '5:' . hash('sha256', $frames[5]) . ':' . $contextHash . ':' . $early, $key);
if ($check($tooSoon) !== 'CAPTURE_SEQUENCE_INVALID') throw new RuntimeException('749ms continuous gap accepted');
$malformed = $continuous; $malformed['departure_tokens'] = [];
if ($check($malformed) !== 'DEPARTURE_CHECK_REQUIRED') throw new RuntimeException('partial legacy departure accepted');
$changed = $frames; $changed[3] .= '-changed';
if ($check($command, $changed) !== 'CANDIDATE_BYTES_CHANGED') throw new RuntimeException('changed bytes accepted');
$changedContext = $command; $changedContext['capture_contexts_b'][1]['frame_width'] = 641;
if ($check($changedContext) !== 'CANDIDATE_BYTES_CHANGED') throw new RuntimeException('changed geometry accepted');
$short = $command;
$short['departure_tokens'][1]['timestamp_ms'] = 1700000002549;
$t = $short['departure_tokens'][1]['timestamp_ms'];
$h = $short['departure_tokens'][1]['sha256'];
$short['departure_tokens'][1]['token'] = hash_hmac('sha256', '9:' . $h . ':' . $t, $key);
if ($check($short) !== 'CAPTURE_SEQUENCE_INVALID') throw new RuntimeException('749ms departure accepted');
$wrongOrder = $command;
$wrongOrder['candidate_tokens'][5] = $wrongOrder['candidate_tokens'][4];
if ($check($wrongOrder) !== 'CANDIDATE_BYTES_CHANGED') throw new RuntimeException('wrong candidate slot accepted');
$tampered = $command; $tampered['departure_tokens'][0]['sha256'] = hash('sha256', 'other');
if ($check($tampered) !== 'DEPARTURE_CHECK_INVALID') throw new RuntimeException('tampered absence accepted');
$missing = $command; unset($missing['candidate_tokens'][7]);
if ($check($missing) !== 'CANDIDATE_CHECK_REQUIRED') throw new RuntimeException('missing candidate accepted');

$write = new ReflectionMethod(FaceEnrollmentService::class, 'writePreviewIndexCache');
$read = new ReflectionMethod(FaceEnrollmentService::class, 'readPreviewIndexCache');
$path = new ReflectionMethod(FaceEnrollmentService::class, 'previewCachePath');
$cacheKey = bin2hex(random_bytes(32));
$write->invoke($service, $cacheKey, [['candidate_ref'=>'test','embedding'=>[0.1,0.2]]], ['test'=>['fullname'=>'Private Test']]);
$cachePath = $path->invoke($service, $cacheKey);
if ($cachePath && is_file($cachePath)) {
    $raw = file_get_contents($cachePath);
    if (strpos($raw, 'Private Test') !== false || strpos($raw, 'candidate_ref') !== false)
        throw new RuntimeException('index cache leaked plaintext');
    $cached = $read->invoke($service, $cacheKey);
    if (($cached['names']['test']['fullname'] ?? null) !== 'Private Test') throw new RuntimeException('index cache failed to decrypt');
    unlink($cachePath);
}
echo "FACE-ENR-12 adaptive evidence PASS\n";
