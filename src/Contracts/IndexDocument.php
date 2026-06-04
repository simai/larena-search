<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class IndexDocument
{
    /**
     * @param array<string, scalar|null> $projection
     * @param list<string> $tokens
     */
    public function __construct(
        public string $documentId,
        public SourceProvider $sourceProvider,
        public array $projection,
        public array $tokens,
        public bool $containsPrivatePayload = false,
    ) {
    }

    public function isIndexable(): bool
    {
        return $this->documentId !== ''
            && $this->sourceProvider->isValid()
            && $this->projection !== []
            && $this->tokens !== []
            && !$this->containsPrivatePayload;
    }
}
