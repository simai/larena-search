<?php

declare(strict_types=1);

namespace Larena\Search\Runtime;

use Larena\Search\Contracts\ReindexSource;
use Larena\Search\Contracts\ReindexSourceFactory;

final readonly class StaticReindexSourceFactory implements ReindexSourceFactory
{
    private string $providerId;

    public function __construct(private ReindexSource $source)
    {
        $this->providerId = $source->providerId();
    }

    public function providerId(): string
    {
        return $this->providerId;
    }

    public function create(): ReindexSource
    {
        return $this->source;
    }
}
