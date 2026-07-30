<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use InvalidArgumentException;

final readonly class SearchProjection
{
    /** @var array<string, scalar|null> */
    public array $payload;

    /** @param array<mixed, mixed> $payload */
    public function __construct(
        public string $providerId,
        public string $sourceRef,
        public int $sourceRevision,
        public string $title,
        public string $locator,
        public string $snippet = '',
        public ?string $locale = null,
        public string $accessScope = 'public',
        public string $searchableText = '',
        array $payload = [],
    ) {
        if (preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $this->providerId) !== 1) {
            throw new InvalidArgumentException('search_provider_id_invalid');
        }
        if (trim($this->sourceRef) === '' || strlen($this->sourceRef) > 191 || $this->sourceRevision < 1) {
            throw new InvalidArgumentException('search_source_identity_invalid');
        }
        if (trim($this->title) === '' || trim($this->locator) === '' || trim($this->accessScope) === '') {
            throw new InvalidArgumentException('search_projection_required_field_missing');
        }
        if (strlen($this->locator) > 2048
            || preg_match('/[\\x00-\\x1F\\x7F\\\\]/', $this->locator) === 1
            || !str_starts_with($this->locator, '/')
            || str_starts_with($this->locator, '//')) {
            throw new InvalidArgumentException('search_projection_locator_unsafe');
        }
        if ($this->locale !== null && preg_match('/^[A-Za-z]{2,3}(?:[-_][A-Za-z0-9]{2,8})?$/', $this->locale) !== 1) {
            throw new InvalidArgumentException('search_projection_locale_invalid');
        }

        $safePayload = [];
        foreach ($payload as $field => $value) {
            if (!is_string($field) || preg_match('/password|token|secret|cookie|session|credential|private|raw_(?:path|content)/i', $field) === 1) {
                throw new InvalidArgumentException('search_projection_payload_field_forbidden');
            }
            if (!is_scalar($value) && $value !== null) {
                throw new InvalidArgumentException('search_projection_payload_value_invalid');
            }
            $safePayload[$field] = $value;
        }

        $this->payload = $safePayload;
    }

    public function contentHash(): string
    {
        $payload = $this->payload;
        ksort($payload);

        return hash('sha256', json_encode([
            'title' => $this->title,
            'locator' => $this->locator,
            'snippet' => $this->snippet,
            'locale' => $this->locale,
            'access_scope' => $this->accessScope,
            'searchable_text' => $this->searchableText,
            'payload' => $payload,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
