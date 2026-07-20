<?php

declare(strict_types=1);

namespace Larena\Search\Runtime;

use InvalidArgumentException;
use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\ReindexSourceFactory;
use Larena\Search\Exceptions\SearchReindexRejected;

final class SearchSourceRegistry
{
    /** @var array<string, ReindexSourceFactory> */
    private array $factories = [];

    public function register(ReindexSource $source): bool
    {
        return $this->registerFactory(new StaticReindexSourceFactory($source));
    }

    public function registerFactory(ReindexSourceFactory $factory): bool
    {
        $providerId = $factory->providerId();
        if (preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $providerId) !== 1) {
            throw new InvalidArgumentException('search_provider_id_invalid');
        }
        if (isset($this->factories[$providerId])) {
            return false;
        }

        $this->factories[$providerId] = $factory;

        return true;
    }

    public function has(string $providerId): bool
    {
        return isset($this->factories[$providerId]);
    }

    public function get(string $providerId): ?ReindexSource
    {
        $factory = $this->factories[$providerId] ?? null;
        if ($factory === null) {
            return null;
        }

        if ($factory->providerId() !== $providerId) {
            throw new SearchReindexRejected('search_reindex_source_provider_mismatch');
        }

        $source = $factory->create();
        if ($source->providerId() !== $providerId) {
            throw new SearchReindexRejected('search_reindex_source_provider_mismatch');
        }

        return $source;
    }

    /** @return list<string> */
    public function providerIds(): array
    {
        $providerIds = array_keys($this->factories);
        sort($providerIds);

        return $providerIds;
    }

    /** @return list<ReindexSource> */
    public function all(): array
    {
        $sources = [];
        foreach ($this->providerIds() as $providerId) {
            $source = $this->get($providerId);
            if ($source !== null) {
                $sources[] = $source;
            }
        }

        return $sources;
    }
}
