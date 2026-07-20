<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

interface ReindexSourceFactory
{
    public function providerId(): string;

    public function create(): ReindexSource;
}
