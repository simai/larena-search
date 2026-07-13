<?php

declare(strict_types=1);

namespace Larena\Search\Exceptions;

use RuntimeException;

final class SearchRevisionConflict extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('search_revision_conflict');
    }
}
