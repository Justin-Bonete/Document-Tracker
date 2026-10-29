<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class DocumentRoute extends Model
{
    /** Route statuses that mean "someone still has to act on this". */
    public const ACTIVE = ['sent', 'acknowledged', 'in_progress', 'escalated'];

    protected $guarded = [];
    protected $casts = [
        'assigned_at' => 'datetime', 'due_at' => 'datetime',
        'acknowledged_at' => 'datetime', 'completed_at' => 'datetime',
    ];

    public function document() { return $this->belongsTo(Document::class); }
    public function events()   { return $this->hasMany(RouteEvent::class, 'route_id')->orderBy('occurred_at')->orderBy('id'); }
}
