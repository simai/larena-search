<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

final readonly class SourceProvider
{
    /**
     * @param list<string> $projectionFields
     */
    public function __construct(
        public string $providerId,
        public string $ownerPackage,
        public array $projectionFields,
        public string $accessScope,
        public bool $includesPrivatePayload = false,
    ) {
    }

    /**
     * @param list<string> $projectionFields
     */
    public static function declare(
        string $providerId,
        string $ownerPackage,
        array $projectionFields,
        string $accessScope,
    ): self {
        return new self($providerId, $ownerPackage, $projectionFields, $accessScope);
    }

    public function isValid(): bool
    {
        return $this->providerId !== ''
            && $this->ownerPackage !== ''
            && $this->projectionFields !== []
            && $this->accessScope !== ''
            && !$this->includesPrivatePayload;
    }
}
