<?php

declare(strict_types=1);

namespace Larena\Search\Contracts;

interface ReindexSource
{
    public function providerId(): string;

    public function readBatch(?string $afterCursor, int $limit): ReindexBatch;
}
