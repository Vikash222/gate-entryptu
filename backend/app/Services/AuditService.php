<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditService
{
    public function log(
        string $action,
        string $module,
        string $status = AuditLog::STATUS_SUCCESS,
        ?array $metadata = null,
        ?string $entityType = null,
        ?string $entityId = null,
        ?User $user = null
    ): AuditLog {
        $userId = $user ? $user->id : Auth::id();
        $ip = Request::ip() ?? '127.0.0.1';
        $userAgent = Request::header('User-Agent') ?? 'System';

        return AuditLog::create([
            'user_id' => $userId,
            'action' => strtoupper($action),
            'module' => strtoupper($module),
            'status' => $status,
            'entity_type' => $entityType,
            'entity_id' => $entityId ? (string) $entityId : null,
            'ip_address' => $ip,
            'user_agent' => substr($userAgent, 0, 500),
            'metadata' => $metadata,
            'server_timestamp' => now('Asia/Kolkata'),
        ]);
    }
}
