<?php

/**
 * Safe structured diagnostics for face workflows.
 *
 * Raw frames, embeddings, templates, credentials, and tokens are removed
 * before error_log() is called. Logging failures never break attendance.
 */
class FaceErrorLogger
{
	private static $blocked = [
		'frame', 'frames', 'image', 'images', 'embedding', 'embeddings',
		'template', 'templates', 'secret', 'shared_secret', 'token', 'password',
		'authorization', 'encrypted_embedding',
	];

	public static function sanitize(array $context): array
	{
		$safe = [];
		foreach ($context as $key => $value) {
			$key = (string)$key;
			$lower = strtolower($key);
			$blocked = false;
			foreach (self::$blocked as $needle) {
				if ($lower === $needle || strpos($lower, $needle) !== false) {
					$blocked = true;
					break;
				}
			}
			if ($blocked) {
				continue;
			}
			if (is_scalar($value) || $value === null) {
				$safe[$key] = $value;
			} elseif (is_array($value)) {
				$safe[$key] = self::sanitize($value);
			} else {
				$safe[$key] = '[REDACTED]';
			}
		}
		return $safe;
	}

	public static function record(string $event, array $context = []): void
	{
		$payload = self::sanitize($context);
		$payload['event'] = $event;
		$payload['component'] = 'face-attendance';
		$payload['timestamp'] = gmdate('c');
		$json = json_encode($payload, JSON_UNESCAPED_SLASHES);
		if (is_string($json)) {
			error_log($json);
		}
	}
}
