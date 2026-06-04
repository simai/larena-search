<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use Larena\Search\Enums\ResultExposureDecision;

final readonly class ScopedSearchResult
{
    public function __construct(
        public IndexDocument $document,
        public ResultExposureDecision $decision,
        public string $title,
        public string $snippet,
    ) {
    }

    public function canReturnToCaller(): bool
    {
        return $this->decision->canExpose() && $this->title !== '';
    }

    public function exposesSnippet(): bool
    {
        return $this->decision === ResultExposureDecision::Allowed && $this->snippet !== '';
    }
}
