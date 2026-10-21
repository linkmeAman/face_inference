<?php

require_once dirname(__DIR__, 2) . '/classes/services/FaceAttendanceApiService.php';
putenv('FACE_TEACHER_SELECTION_ENABLED=1');

function padAssertSame($expected, $actual, string $label): void
{
    if ($expected !== $actual) {
        throw new RuntimeException("{$label}: expected {$expected}, got {$actual}");
    }
}

$reflection = new ReflectionClass(FaceAttendanceApiService::class);
$service = $reflection->newInstanceWithoutConstructor();
$prepare = $reflection->getMethod('prepareAttempt');
$prepare->setAccessible(true);

$decisionFor = function (string $liveness) use ($service, $prepare): string {
    $result = $prepare->invoke($service, [
        'decision' => 'ACCEPT',
        'candidate_ref' => 'candidate-a',
        'liveness' => $liveness,
        'quality' => ['face_count' => 1],
    ]);
    return $result['decision'];
};

padAssertSame('ACCEPT', $decisionFor('LIVE'), 'LIVE acceptance');
padAssertSame('REVIEW', $decisionFor('NOT_RUN'), 'NOT_RUN downgrade');
padAssertSame('REVIEW', $decisionFor('UNKNOWN'), 'UNKNOWN downgrade');
padAssertSame('REVIEW', $decisionFor('CHALLENGE'), 'CHALLENGE downgrade');
padAssertSame('REVIEW', $decisionFor('unexpected'), 'invalid liveness downgrade');
padAssertSame('REJECT', $decisionFor('SPOOF'), 'SPOOF rejection');

$challengePrepared = $prepare->invoke($service, [
    'decision' => 'ACCEPT',
    'candidate_ref' => 'candidate-a',
    'liveness' => 'CHALLENGE',
    'liveness_score' => 0.55,
    'liveness_reason' => 'PASSIVE_BORDERLINE',
    'challenge' => ['type' => 'LOOK_LEFT', 'token' => str_repeat('a', 43), 'expires_in' => 15],
]);
padAssertSame('CHALLENGE', $challengePrepared['liveness'], 'challenge liveness');
padAssertSame('LOOK_LEFT', $challengePrepared['challenge']['type'] ?? null, 'challenge type forwarding');
padAssertSame('PASSIVE_BORDERLINE', $challengePrepared['liveness_reason'] ?? null, 'safe PAD reason forwarding');

echo "PASS FACE-PAD-01 fail-closed liveness gate\n";
