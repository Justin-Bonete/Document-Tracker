<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Append-only: updates and deletes are blocked at the application layer. */
class AuditLog extends Model
{
    public $timestamps = false;
    protected $guarded = [];
    protected $casts = ['before_json' => 'array', 'after_json' => 'array', 'created_at' => 'datetime'];

    public function actor() { return $this->belongsTo(User::class, 'actor_user_id'); }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs is append-only.'));
        static::deleting(fn () => throw new LogicException('audit_logs is append-only.'));
    }
}
