<?php

declare(strict_types=1);

namespace Larena\Search\Admin;

use Illuminate\Contracts\Translation\Translator;
use Larena\Admin\Record\AdminRecordLayoutStore;
use Larena\Admin\Runtime\PropertyViewPort;
use Larena\Search\Operations\SearchIndexOperationsQuery;

/**
 * A source of the search index as a record of the Framework property view (admin page standard): collection
 * «search.providers», record = the provider's id. It is read here; reindexing it is an action of its row.
 */
final class SearchProviderPropertyViewPort extends PropertyViewPort
{
    public const COLLECTION = 'search.providers';

    public function __construct(
        private SearchIndexOperationsQuery $operations,
        private Translator $translator,
        AdminRecordLayoutStore $layouts,
    ) {
        parent::__construct($layouts);
    }

    protected function collectionPattern(): string
    {
        return '/^search\.providers$/';
    }

    protected function recordPattern(): string
    {
        return '/^[a-z][a-z0-9_.-]{1,119}$/';
    }

    protected function conflictReason(): string
    {
        return 'search_provider_read_only';
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>}|array<string,mixed> */
    protected function readObject(string $actor, string $scope, string $collection, string $recordId): array
    {
        $provider = $this->operations->provider($recordId);
        if ($provider === null) {
            return ['answer' => 'refused', 'reason' => 'record_unknown'];
        }
        $text = fn (string $key): string => (string) $this->translator->get('larena-search::admin.'.$key);
        $fields = [];
        foreach (['provider', 'state', 'progress', 'generation', 'error'] as $key) {
            $fields[] = ['key' => $key, 'label' => $text($key), 'type' => 'string', 'type_version' => 1, 'constraints' => [], 'readonly' => true];
        }

        return [['structure_id' => self::COLLECTION, 'label' => $text('heading'), 'fields' => $fields],
            ['record_id' => $recordId, 'revision' => 1, 'editable' => false, 'values' => SearchProviderRows::cells($provider, $text)]];
    }

    /** @param array<string,mixed> $values @return array<string,mixed> */
    protected function writeObject(string $actor, string $scope, string $collection, string $recordId, int|string $revision, array $values): array
    {
        return ['status' => 'rejected', 'reason_code' => $this->conflictReason()];
    }
}
