<?php

/**
 * Stable application-facing recognition contract.
 *
 * Provider adapters return recognition evidence only. They cannot write
 * attendance, membership, consent, roster, or enrollment state.
 */
interface FaceRecognitionProvider
{
	/** A scan may start or continue only while the private model is ready. */
	public function checkReadiness(): bool;
	/**
	 * @return array{provider_id:string,provider_version:string,model_version_id:int,model_version:string,calibration_policy_version:string}
	 */
	public function providerMetadata(): array;

	public function initializeScan(string $scanId, array $candidates, int $ttl): array;

	public function recognizeAttempt(string $scanId, array $frames, string $challengeToken = '', array $captureContexts = []): array;

	public function deleteScan(string $scanId): void;
}
