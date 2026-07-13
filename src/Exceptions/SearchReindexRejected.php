<?php

declare(strict_types=1);

namespace Larena\Search\Exceptions;

use RuntimeException;
use Throwable;

final class SearchReindexRejected extends RuntimeException
{
    public function __construct(public readonly string $reasonCode, ?Throwable $previous = null)
    {
        parent::__construct($reasonCode, 0, $previous);
    }
}
