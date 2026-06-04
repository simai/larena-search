<?php

declare(strict_types=1);

namespace Larena\Search\Enums;

enum EngineProfileType: string
{
    case Database = 'database';
    case Native = 'native';
    case External = 'external';
    case Semantic = 'semantic';

    public function requiresCapabilityGate(): bool
    {
        return $this === self::External || $this === self::Semantic;
    }
}
