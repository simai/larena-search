<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class SearchHit
{
    /**
     * @param array<string, scalar|null> $payload
     */
    public function __construct(
        public string $providerId,
        public string $sourceRef,
        public int $sourceRevision,
        public string $title,
        public string $locator,
        public string $snippet,
        public ?string $locale,
        public string $accessScope,
        public array $payload,
    ) {
    }
}
