<?php

declare(strict_types=1);

namespace Larena\Search\Audit;

use InvalidArgumentException;
use Larena\Audit\Contracts\AuditEventDescriptor;
use Larena\Audit\Enums\AuditRetentionClass;
use Larena\Audit\Enums\AuditSeverity;

final readonly class SearchReindexAuditEventDescriptor implements AuditEventDescriptor
{
    private const TYPES = [
        'search.reindex.started',
        'search.reindex.resumed',
        'search.reindex.checkpointed',
        'search.reindex.completed',
        'search.reindex.failed',
    ];

    public function __construct(private string $eventType)
    {
        if (!in_array($eventType, self::TYPES, true)) {
            throw new InvalidArgumentException('search_audit_event_type_invalid');
        }
    }

    public function sourcePackage(): string { return 'larena/search'; }

    public function category(): string { return 'search_reindex'; }

    public function type(): string { return $this->eventType; }

    public function severity(): AuditSeverity { return AuditSeverity::Security; }

    public function retentionClass(): AuditRetentionClass { return AuditRetentionClass::Security; }

    public function redactedPayloadFields(): array { return []; }

    public function forbiddenPayloadFields(): array
    {
        return ['password', 'password_hash', 'session_id', 'cookie', 'token', 'secret', 'query', 'title', 'snippet', 'body', 'payload'];
    }

    public function isExperimental(): bool { return false; }
}
