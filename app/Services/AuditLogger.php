<?php
namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class AuditLogger
{
    /** Record one audit event. Call this inside the same DB transaction as the change. */
    public static function record(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?array $before = null,
        ?array $after = null,
    ): AuditLog {
        $req = request();

        return AuditLog::create([
            'actor_user_id' => Auth::id(),
            'action'        => $action,
            'entity_type'   => $entityType,
            'entity_id'     => $entityId,
            'request_id'    => $req->attributes->get('request_id'),
            'ip_address'    => $req->ip(),
            'user_agent'    => substr((string) $req->userAgent(), 0, 255),
            'before_json'   => $before,
            'after_json'    => $after,
        ]);
    }
}
