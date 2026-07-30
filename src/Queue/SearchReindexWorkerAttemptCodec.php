<?php

declare(strict_types=1);

namespace Larena\Search\Queue;

use InvalidArgumentException;
use JsonException;
use Larena\Search\Contracts\ReindexRun;

/** @internal Creates and verifies revision-local worker attempt claims. */
final readonly class SearchReindexWorkerAttemptCodec
{
    private const OPERATIONS = ['run', 'resume', 'retry', 'continue'];

    public function __construct(private string $key)
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException('search_reindex_worker_key_invalid');
        }
    }

    public function issue(ReindexRun $run, string $operation, string $actorRef, int $batchSize): string
    {
        if (!in_array($operation, self::OPERATIONS, true) || $batchSize < 1 || $batchSize > 1000) {
            throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
        }

        $claims = [
            'v' => 1,
            'operation' => $operation,
            'run_ref' => $run->runRef,
            'provider_id' => $run->providerId,
            'generation_ref' => $run->generationRef,
            'expected_state' => $run->state,
            'expected_cursor_hash' => $run->cursor === null ? null : hash('sha256', $run->cursor),
            'expected_processed_count' => $run->processedCount,
            'expected_batch_count' => $run->batchCount,
            'actor_ref' => $actorRef,
            'batch_size' => $batchSize,
        ];
        $claims['attempt_ref'] = substr(hash_hmac('sha256', $this->json($claims), $this->key), 0, 32);
        $payload = $this->base64UrlEncode($this->json($claims));

        return $payload . '.' . $this->base64UrlEncode(hash_hmac('sha256', $payload, $this->key, true));
    }

    public function decode(string $token): SearchReindexWorkerAttempt
    {
        $parts = explode('.', $token, 3);
        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
        }
        $expected = hash_hmac('sha256', $parts[0], $this->key, true);
        $signature = $this->base64UrlDecode($parts[1]);
        if ($signature === null || !hash_equals($expected, $signature)) {
            throw new InvalidArgumentException('search_reindex_worker_attempt_forged');
        }
        $payload = $this->base64UrlDecode($parts[0]);
        if ($payload === null) {
            throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
        }

        try {
            $claims = json_decode($payload, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
        }
        if (!is_array($claims) || array_keys($claims) !== [
            'v', 'operation', 'run_ref', 'provider_id', 'generation_ref', 'expected_state',
            'expected_cursor_hash', 'expected_processed_count', 'expected_batch_count',
            'actor_ref', 'batch_size', 'attempt_ref',
        ]) {
            throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
        }
        if ($claims['v'] !== 1 || !is_string($claims['operation']) || !in_array($claims['operation'], self::OPERATIONS, true)) {
            throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
        }
        foreach (['run_ref', 'provider_id', 'generation_ref', 'expected_state', 'actor_ref', 'attempt_ref'] as $field) {
            if (!is_string($claims[$field]) || $claims[$field] === '') {
                throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
            }
        }
        if (($claims['expected_cursor_hash'] !== null && (!is_string($claims['expected_cursor_hash']) || preg_match('/^[a-f0-9]{64}$/', $claims['expected_cursor_hash']) !== 1))
            || !is_int($claims['expected_processed_count']) || $claims['expected_processed_count'] < 0
            || !is_int($claims['expected_batch_count']) || $claims['expected_batch_count'] < 0
            || !is_int($claims['batch_size']) || $claims['batch_size'] < 1 || $claims['batch_size'] > 1000
            || preg_match('/^[a-f0-9]{32}$/', $claims['attempt_ref']) !== 1) {
            throw new InvalidArgumentException('search_reindex_worker_attempt_invalid');
        }

        return new SearchReindexWorkerAttempt(
            $claims['attempt_ref'], $claims['operation'], $claims['run_ref'], $claims['provider_id'],
            $claims['generation_ref'], $claims['expected_state'], $claims['expected_cursor_hash'],
            $claims['expected_processed_count'], $claims['expected_batch_count'], $claims['actor_ref'], $claims['batch_size'],
        );
    }

    /** @param array<string, mixed> $value */
    private function json(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    private function base64UrlDecode(string $value): ?string
    {
        if ($value === '' || preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }
        $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);

        return is_string($decoded) ? $decoded : null;
    }
}
