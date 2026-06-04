<?php

declare(strict_types=1);

namespace Larena\Search\Enums;

enum ReindexJobStatus: string
{
    case Planned = 'planned';
    case Queued = 'queued';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';

    public function canResume(): bool
    {
        return $this === self::Planned || $this === self::Queued || $this === self::Paused || $this === self::Failed;
    }
}
