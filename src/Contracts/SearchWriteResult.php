<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class SearchWriteResult
{
    public function __construct(
        public string $status,
        public string $providerId,
        public string $sourceRef,
        public int $sourceRevision,
        public bool $changed,
    ) {
    }

    public static function changed(string $status, string $providerId, string $sourceRef, int $sourceRevision): self
    {
        return new self($status, $providerId, $sourceRef, $sourceRevision, true);
    }

    public static function unchanged(string $status, string $providerId, string $sourceRef, int $sourceRevision): self
    {
        return new self($status, $providerId, $sourceRef, $sourceRevision, false);
    }
}
