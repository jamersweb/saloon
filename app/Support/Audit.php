<?php

namespace App\Support;

use App\Models\AuditLog;

class Audit
{
    public static function log(?int $userId, string $action, string $entityType, ?int $entityId = null, array $metadata = []): void
    {
        AuditLog::create([
            'user_id' => $userId,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'metadata' => $metadata,
        ]);
    }
}
