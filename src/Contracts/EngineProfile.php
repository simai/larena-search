<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use Larena\Search\Enums\EngineProfileType;

final readonly class EngineProfile
{
    public function __construct(
        public string $profileId,
        public EngineProfileType $type,
        public bool $capabilityAllowed = true,
        public bool $engineAvailable = true,
    ) {
    }

    public static function databaseBaseline(): self
    {
        return new self(profileId: 'database', type: EngineProfileType::Database);
    }

    public function canRun(): bool
    {
        if ($this->profileId === '' || !$this->engineAvailable) {
            return false;
        }

        return !$this->type->requiresCapabilityGate() || $this->capabilityAllowed;
    }

    public function isDegraded(): bool
    {
        return !$this->engineAvailable || !$this->canRun();
    }
}
