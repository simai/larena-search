<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

interface SearchRuntime
{
    public function registerSource(SourceProvider $sourceProvider): SourceProvider;

    public function createDocument(SourceProvider $sourceProvider, array $projection, array $tokens): IndexDocument;

    public function exposeResult(
        IndexDocument $document,
        QueryContext $queryContext,
        ResultExposurePolicy $policy,
    ): ScopedSearchResult;

    public function planReindex(SourceProvider $sourceProvider, EngineProfile $engineProfile): ReindexJob;
}
