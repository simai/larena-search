<?php

declare(strict_types=1);

namespace Larena\Search\Enums;

enum ResultExposureDecision: string
{
    case Allowed = 'allowed';
    case Redacted = 'redacted';
    case Denied = 'denied';
    case Hidden = 'hidden';

    public function canExpose(): bool
    {
        return $this === self::Allowed || $this === self::Redacted;
    }
}
