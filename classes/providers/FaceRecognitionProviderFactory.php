<?php

require_once dirname(__DIR__) . '/clients/FaceInferenceClient.php';

/** Composition root for recognition/enrollment provider adapters. */
final class FaceRecognitionProviderFactory
{
	public static function create()
	{
		$className = class_exists('FaceAttendanceConfig') ? FaceAttendanceConfig::getEnv('FACE_RECOGNITION_PROVIDER_CLASS') : (getenv('FACE_RECOGNITION_PROVIDER_CLASS') ?: ($_SERVER['FACE_RECOGNITION_PROVIDER_CLASS'] ?? 'FaceInferenceClient'));
		$className = trim($className ?: 'FaceInferenceClient');
		$adapterFile = class_exists('FaceAttendanceConfig') ? FaceAttendanceConfig::getEnv('FACE_RECOGNITION_PROVIDER_ADAPTER_FILE') : (getenv('FACE_RECOGNITION_PROVIDER_ADAPTER_FILE') ?: ($_SERVER['FACE_RECOGNITION_PROVIDER_ADAPTER_FILE'] ?? ''));
		$adapterFile = trim($adapterFile);
		if ($adapterFile !== '') {
			$providerDirectory = realpath(__DIR__);
			$resolved = realpath($adapterFile);
			if ($providerDirectory === false || $resolved === false || strpos($resolved, $providerDirectory . DIRECTORY_SEPARATOR) !== 0) {
				throw new RuntimeException('Recognition provider adapter must be inside classes/providers');
			}
			require_once $resolved;
		}
		if ($className === '' || !class_exists($className)) {
			throw new RuntimeException('Configured recognition provider class is unavailable');
		}
		$provider = new $className();
		if (!$provider instanceof FaceRecognitionProvider || !$provider instanceof FaceEnrollmentInferenceClient) {
			throw new RuntimeException('Configured provider does not implement the face provider contracts');
		}
		foreach (['previewAttendance', 'previewEnrollment'] as $method) {
			if (!method_exists($provider, $method)) {
				throw new RuntimeException('Configured provider does not support required preview operations');
			}
		}
		return $provider;
	}
}
