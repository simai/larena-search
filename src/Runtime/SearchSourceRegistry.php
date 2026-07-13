<?php

declare(strict_types=1);

namespace Larena\Search\Runtime;

use InvalidArgumentException;
use Larena\Search\Contracts\ReindexSource;

final class SearchSourceRegistry
{
    /** @var array<string, ReindexSource> */
    private array $sources = [];

    public function register(ReindexSource $source): bool
    {
        $providerId = $source->providerId();
        if (preg_match('/^[a-z][a-z0-9_.-]{1,119}$/', $providerId) !== 1) {
            throw new InvalidArgumentException('search_provider_id_invalid');
        }
        if (isset($this->sources[$providerId])) {
            return false;
        }

        $this->sources[$providerId] = $source;

        return true;
    }

    public function get(string $providerId): ?ReindexSource
    {
        return $this->sources[$providerId] ?? null;
    }

    /** @return list<ReindexSource> */
    public function all(): array
    {
        $sources = array_values($this->sources);
        usort($sources, static fn (ReindexSource $left, ReindexSource $right): int => $left->providerId() <=> $right->providerId());

        return $sources;
    }
}
