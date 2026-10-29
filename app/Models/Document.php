<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use SoftDeletes;

    public const LOCKED = ['closed', 'archived', 'voided'];

    protected $guarded = [];
    protected $casts = ['received_at' => 'datetime', 'due_at' => 'datetime', 'closed_at' => 'datetime'];

    public function type()    { return $this->belongsTo(DocumentType::class, 'document_type_id'); }
    public function files()   { return $this->hasMany(DocumentFile::class)->orderBy('original_name')->orderBy('version_no'); }
    public function routes()  { return $this->hasMany(DocumentRoute::class); }
    public function tags()    { return $this->belongsToMany(Tag::class, 'document_tags'); }
    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /**
     * The ONE place that decides which documents a user may see.
     * Use it on every query (list, detail, files, history, downloads, later exports/reports).
     * Out-of-scope records simply do not exist for the user (404, not 403) so IDs cannot be probed.
     */
    public function scopeVisibleTo(Builder $q, User $u): Builder
    {
        if (! $u->hasPermission('documents.view')) {
            return $q->whereRaw('1 = 0');
        }
        if (! $u->hasPermission('documents.view_confidential')) {
            $q->where('classification', '!=', 'confidential');
        }
        if ($u->hasPermission('documents.view_all')) {
            return $q;
        }

        $offices = $u->offices()->pluck('offices.id')->all();

        return $q->where(function (Builder $w) use ($u, $offices) {
            $w->where('created_by', $u->id)
              ->orWhere('current_assignee_id', $u->id)
              ->orWhereIn('current_office_id', $offices)
              ->orWhereIn('origin_office_id', $offices)
              ->orWhereHas('routes', function (Builder $r) use ($u, $offices) {
                  $r->where('from_user_id', $u->id)
                    ->orWhere('to_user_id', $u->id)
                    ->orWhereIn('from_office_id', $offices)
                    ->orWhereIn('to_office_id', $offices);
              });
        });
    }
}
