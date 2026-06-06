<?php

declare(strict_types=1);

namespace Larena\Search\Runtime;

use Larena\Search\Contracts\EngineProfile;
use Larena\Search\Contracts\IndexDocument;
use Larena\Search\Contracts\QueryContext;
use Larena\Search\Contracts\ReindexJob;
use Larena\Search\Contracts\ResultExposurePolicy;
use Larena\Search\Contracts\ScopedSearchResult;
use Larena\Search\Contracts\SearchRuntime;
use Larena\Search\Contracts\SourceProvider;

final class InMemorySearchRuntime implements SearchRuntime
{
    /**
     * @var array<string, SourceProvider>
     */
    private array $sources = [];

    /**
     * @var array<string, IndexDocument>
     */
    private array $documents = [];

    public function registerSource(SourceProvider $sourceProvider): SourceProvider
    {
        if ($sourceProvider->isValid()) {
            $this->sources[$sourceProvider->providerId] = $sourceProvider;
        }

        return $sourceProvider;
    }

    /**
     * @param array<string, scalar|null> $projection
     * @param list<string> $tokens
     */
    public function createDocument(SourceProvider $sourceProvider, array $projection, array $tokens): IndexDocument
    {
        $safeProjection = [];
        foreach ($sourceProvider->projectionFields as $field) {
            if (array_key_exists($field, $projection)) {
                $safeProjection[$field] = $projection[$field];
            }
        }

        $normalizedTokens = array_values(array_filter(
            array_map(static fn (string $token): string => trim(strtolower($token)), $tokens),
            static fn (string $token): bool => $token !== '',
        ));

        $documentId = $sourceProvider->providerId === '' ? '' : $sourceProvider->providerId . ':' . sha1(json_encode($safeProjection) ?: '');

        return new IndexDocument(
            documentId: $documentId,
            sourceProvider: $sourceProvider,
            projection: $safeProjection,
            tokens: $normalizedTokens,
            containsPrivatePayload: $sourceProvider->includesPrivatePayload || $this->projectionLooksPrivate($projection),
        );
    }

    public function exposeResult(
        IndexDocument $document,
        QueryContext $queryContext,
        ResultExposurePolicy $policy,
    ): ScopedSearchResult {
        $decision = $policy->decide($document, $queryContext);

        return new ScopedSearchResult(
            document: $document,
            decision: $decision,
            title: $this->safeTitle($document),
            snippet: $policy->snippetAllowed ? $this->safeSnippet($document) : '',
        );
    }

    public function planReindex(SourceProvider $sourceProvider, EngineProfile $engineProfile): ReindexJob
    {
        $jobId = $sourceProvider->isValid() && $engineProfile->canRun()
            ? 'reindex:' . $sourceProvider->providerId
            : '';

        return new ReindexJob(
            jobId: $jobId,
            sourceProvider: $sourceProvider,
            engineProfile: $engineProfile,
        );
    }

    public function ingest(IndexDocument $document): bool
    {
        if (!$document->isIndexable()) {
            return false;
        }

        $this->documents[$document->documentId] = $document;

        return true;
    }

    /**
     * @return list<ScopedSearchResult>
     */
    public function query(QueryContext $queryContext, ResultExposurePolicy $policy): array
    {
        if (!$queryContext->isValid()) {
            return [];
        }

        $results = [];
        foreach ($this->documents as $document) {
            if (!$this->matches($document, $queryContext->query)) {
                continue;
            }

            $result = $this->exposeResult($document, $queryContext, $policy);
            if ($result->canReturnToCaller()) {
                $results[] = $result;
            }
        }

        return $results;
    }

    /**
     * @return array<string, SourceProvider>
     */
    public function registeredSources(): array
    {
        return $this->sources;
    }

    /**
     * @return array<string, IndexDocument>
     */
    public function indexedDocuments(): array
    {
        return $this->documents;
    }

    private function matches(IndexDocument $document, string $query): bool
    {
        $needle = trim(strtolower($query));
        if ($needle === '') {
            return false;
        }

        foreach ($document->tokens as $token) {
            if (str_contains($token, $needle)) {
                return true;
            }
        }

        foreach ($document->projection as $value) {
            if ($value !== null && str_contains(strtolower((string) $value), $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, scalar|null> $projection
     */
    private function projectionLooksPrivate(array $projection): bool
    {
        $privateMarkers = ['secret', 'token', 'password', 'credential', 'private_payload', 'raw_path', 'raw_content'];
        foreach (array_keys($projection) as $field) {
            $normalized = strtolower((string) $field);
            foreach ($privateMarkers as $marker) {
                if (str_contains($normalized, $marker)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function safeTitle(IndexDocument $document): string
    {
        foreach (['title', 'name', 'label'] as $field) {
            $value = $document->projection[$field] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return $document->documentId;
    }

    private function safeSnippet(IndexDocument $document): string
    {
        foreach (['summary', 'description', 'excerpt'] as $field) {
            $value = $document->projection[$field] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '';
    }
}
