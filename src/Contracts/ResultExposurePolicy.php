<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

use Larena\Search\Enums\ResultExposureDecision;

final readonly class ResultExposurePolicy
{
    public function __construct(
        public bool $accessScopeMatched,
        public bool $snippetAllowed,
        public bool $hideDeniedExistence = true,
    ) {
    }

    public static function denyByDefault(): self
    {
        return new self(accessScopeMatched: false, snippetAllowed: false);
    }

    public function decide(IndexDocument $document, QueryContext $context): ResultExposureDecision
    {
        if (!$context->isValid() || !$document->isIndexable()) {
            return ResultExposureDecision::Denied;
        }

        if (!$this->accessScopeMatched || $context->accessScope !== $document->sourceProvider->accessScope) {
            return $this->hideDeniedExistence ? ResultExposureDecision::Hidden : ResultExposureDecision::Denied;
        }

        return $this->snippetAllowed ? ResultExposureDecision::Allowed : ResultExposureDecision::Redacted;
    }
}
