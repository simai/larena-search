<?php

declare(strict_types=1);

namespace Larena\Search\Exceptions;

use RuntimeException;
use Throwable;

final class SearchPersistenceFailed extends RuntimeException
{
    public function __construct(?Throwable $previous = null)
    {
        parent::__construct('search_persistence_failed', 0, $previous);
    }

    public static function from(Throwable $throwable): self
    {
        return $throwable instanceof self ? $throwable : new self($throwable);
    }
}
