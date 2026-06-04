<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use Larena\Search\Enums\ReindexJobStatus;

final readonly class ReindexJob
{
    public function __construct(
        public string $jobId,
        public SourceProvider $sourceProvider,
        public EngineProfile $engineProfile,
        public ReindexJobStatus $status = ReindexJobStatus::Planned,
        public int $processed = 0,
        public int $failed = 0,
    ) {
    }

    public function canStart(): bool
    {
        return $this->jobId !== ''
            && $this->sourceProvider->isValid()
            && $this->engineProfile->canRun()
            && $this->status->canResume();
    }

    public function hasSafeDiagnostics(): bool
    {
        return $this->jobId !== '' && $this->failed >= 0 && $this->processed >= 0;
    }
}
