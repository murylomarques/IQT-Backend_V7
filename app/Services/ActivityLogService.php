<?php

namespace App\Services;

use App\Models\ActivityLog;
use Illuminate\Support\Facades\Auth;

class ActivityLogService
{
    public static function log(
        string $action,
        string $description,
        ?int $userId = null,
        array $metadata = [],
        ?string $entityType = null,
        ?int $entityId = null
    ): void {
        ActivityLog::create([
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'description' => $description,
            'metadata' => empty($metadata) ? null : $metadata,
        ]);
    }
}

