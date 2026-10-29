<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Document;
use App\Services\AuditLogger;
use App\Services\DocumentRegistrar;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class DocumentController extends Controller
{
    private const PRIORITIES      = ['low', 'normal', 'high', 'urgent'];
    private const CLASSIFICATIONS = ['public', 'internal', 'confidential'];

    public function index(Request $r)
    {
        $f = $r->validate([
            'q'                  => ['nullable', 'string', 'max:100'],
            'status'             => ['nullable', 'string', 'max:30'],
            'priority'           => ['nullable', 'string', 'max:20'],
            'direction'          => ['nullable', 'string', 'max:20'],
            'classification'     => ['nullable', 'string', 'max:30'],
            'office_id'          => ['nullable', 'integer'],
            'document_type_code' => ['nullable', 'string', 'max:50'],
            'tag'                => ['nullable', 'string', 'max:50'],
            'received_from'      => ['nullable', 'date'],
            'received_to'        => ['nullable', 'date'],
            'overdue'            => ['nullable', 'boolean'],
            'sort'               => ['nullable', Rule::in(['created_at', 'due_at', 'received_at', 'title'])],
            'dir'                => ['nullable', Rule::in(['asc', 'desc'])],
            'per_page'           => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        // visibleTo() is applied first, so every filter below only ever narrows what the user may see.
        $q = Document::query()->visibleTo($r->user())->with('type:id,code,name');

        if (! empty($f['q'])) {
            $like = '%' . addcslashes($f['q'], '%_\\') . '%';
            $q->where(fn ($w) => $w->where('title', 'like', $like)
                ->orWhere('tracking_no', 'like', $like)
                ->orWhere('sender_name', 'like', $like)
                ->orWhere('recipient_name', 'like', $like));
        }
        foreach (['status', 'priority', 'direction', 'classification'] as $col) {
            if (! empty($f[$col])) {
                $q->where($col, $f[$col]);
            }
        }
        if (! empty($f['office_id'])) {
            $q->where('current_office_id', $f['office_id']);
        }
        if (! empty($f['document_type_code'])) {
            $q->whereHas('type', fn ($t) => $t->where('code', $f['document_type_code']));
        }
        if (! empty($f['tag'])) {
            $q->whereHas('tags', fn ($t) => $t->where('name', mb_strtolower($f['tag'])));
        }
        if (! empty($f['received_from'])) {
            $q->where('received_at', '>=', DocumentRegistrar::utc($f['received_from']));
        }
        if (! empty($f['received_to'])) {
            $q->where('received_at', '<=', DocumentRegistrar::utc($f['received_to']));
        }
        if (! empty($f['overdue'])) {
            $q->where('due_at', '<', now())->whereNotIn('status', Document::LOCKED);
        }

        $q->orderBy($f['sort'] ?? 'created_at', $f['dir'] ?? 'desc')->orderByDesc('id');
        $p = $q->paginate($f['per_page'] ?? 20);

        return Api::ok(
            $p->getCollection()->map(fn ($d) => $this->summary($d))->values(),
            200,
            ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        );
    }

    public function store(Request $r, DocumentRegistrar $registrar)
    {
        $data = $r->validate([
            'title'              => ['required', 'string', 'max:255'],
            'description'        => ['nullable', 'string', 'max:5000'],
            'document_type_code' => ['required', 'string', 'exists:document_types,code'],
            'direction'          => ['required', Rule::in(['incoming', 'outgoing', 'internal'])],
            'sender_name'        => ['nullable', 'string', 'max:255'],
            'recipient_name'     => ['nullable', 'string', 'max:255'],
            'priority'           => ['nullable', Rule::in(self::PRIORITIES)],
            'classification'     => ['nullable', Rule::in(self::CLASSIFICATIONS)],
            'origin_office_id'   => ['required', 'integer', 'exists:offices,id'],
            'received_at'        => ['nullable', 'date'],
            'due_at'             => ['nullable', 'date'],
            'physical_location'  => ['nullable', 'string', 'max:255'],
            'tags'               => ['nullable', 'array', 'max:10'],
            'tags.*'             => ['string', 'max:50'],
        ]);

        $u = $r->user();

        // Never trust a client-supplied office: the user must belong to it.
        if (! $u->hasPermission('documents.view_all')
            && ! $u->offices()->where('offices.id', $data['origin_office_id'])->exists()) {
            return Api::error('FORBIDDEN_OFFICE', 'You are not a member of the selected office.', 403);
        }
        if (($data['classification'] ?? null) === 'confidential' && ! $u->hasPermission('documents.view_confidential')) {
            return Api::error('FORBIDDEN_CLASSIFICATION', 'You cannot register confidential documents.', 403);
        }

        $doc = $registrar->register($data, $u);

        return Api::ok([
            'id'          => $doc->id,
            'tracking_no' => $doc->tracking_no,
            'status'      => $doc->status,
            'created_at'  => $doc->created_at->toIso8601ZuluString(),
        ], 201);
    }

    public function show(Request $r, int $id)
    {
        $doc = Document::visibleTo($r->user())->with(['type', 'tags', 'files', 'routes'])->findOrFail($id);

        if ($doc->classification === 'confidential') {
            AuditLogger::record('document.view', 'document', $doc->id);
        }
        return Api::ok($this->detail($doc));
    }

    public function update(Request $r, int $id)
    {
        $u   = $r->user();
        $doc = Document::visibleTo($u)->findOrFail($id);

        if (in_array($doc->status, Document::LOCKED, true)) {
            return Api::error('DOCUMENT_LOCKED', 'Closed, archived or voided documents are read-only.', 409);
        }

        $data = $r->validate([
            'title'             => ['sometimes', 'required', 'string', 'max:255'],
            'description'       => ['sometimes', 'nullable', 'string', 'max:5000'],
            'sender_name'       => ['sometimes', 'nullable', 'string', 'max:255'],
            'recipient_name'    => ['sometimes', 'nullable', 'string', 'max:255'],
            'priority'          => ['sometimes', Rule::in(self::PRIORITIES)],
            'classification'    => ['sometimes', Rule::in(self::CLASSIFICATIONS)],
            'physical_location' => ['sometimes', 'nullable', 'string', 'max:255'],
            'due_at'            => ['sometimes', 'nullable', 'date'],
            'tags'              => ['sometimes', 'array', 'max:10'],
            'tags.*'            => ['string', 'max:50'],
        ]);

        if (($data['classification'] ?? null) === 'confidential' && ! $u->hasPermission('documents.view_confidential')) {
            return Api::error('FORBIDDEN_CLASSIFICATION', 'You cannot mark documents confidential.', 403);
        }

        return DB::transaction(function () use ($doc, $data) {
            $fields = Arr::except($data, ['tags']);
            if (array_key_exists('due_at', $fields)) {
                $fields['due_at'] = DocumentRegistrar::utc($fields['due_at']);
            }

            $doc->fill($fields);
            $dirty  = $doc->getDirty();
            $before = Arr::only($doc->getOriginal(), array_keys($dirty));

            if (array_key_exists('tags', $data)) {
                $before['tags'] = $doc->tags()->pluck('name')->sort()->values()->all();
                DocumentRegistrar::syncTags($doc, $data['tags']);
                $dirty['tags'] = $doc->tags()->pluck('name')->sort()->values()->all();
            }

            if ($dirty) {
                $doc->save();
                AuditLogger::record('document.update', 'document', $doc->id, $before, $dirty);
            }
            return Api::ok($this->summary($doc->load('type:id,code,name')));
        });
    }

    /** "Delete" = void: soft-delete with a mandatory reason and an audit event. */
    public function destroy(Request $r, int $id)
    {
        $reason = $r->validate(['reason' => ['required', 'string', 'min:5', 'max:500']])['reason'];
        $doc    = Document::visibleTo($r->user())->findOrFail($id);

        DB::transaction(function () use ($doc, $reason) {
            $before = ['status' => $doc->status];
            $doc->update(['status' => 'voided', 'void_reason' => $reason]);
            $doc->delete();
            AuditLogger::record('document.void', 'document', $doc->id, $before, ['status' => 'voided', 'reason' => $reason]);
        });

        return response()->noContent();
    }

    public function history(Request $r, int $id)
    {
        $doc = Document::visibleTo($r->user())->findOrFail($id);
        $full = $r->user()->hasPermission('audit.view');   // before/after values only for auditors

        $rows = AuditLog::with('actor:id,name')
            ->where('entity_type', 'document')->where('entity_id', $doc->id)
            ->orderBy('created_at')->orderBy('id')->get()
            ->map(fn ($a) => array_filter([
                'id'         => $a->id,
                'action'     => $a->action,
                'actor'      => $a->actor ? ['id' => $a->actor->id, 'name' => $a->actor->name] : null,
                'created_at' => $a->created_at->toIso8601ZuluString(),
                'request_id' => $a->request_id,
                'before'     => $full ? $a->before_json : null,
                'after'      => $full ? $a->after_json : null,
            ], fn ($v) => $v !== null))
            ->values();

        return Api::ok($rows);
    }

    // ---------- presenters ----------
    private function iso($date): ?string
    {
        return $date?->toIso8601ZuluString();
    }

    private function summary(Document $d): array
    {
        return [
            'id'                  => $d->id,
            'tracking_no'         => $d->tracking_no,
            'title'               => $d->title,
            'type'                => $d->type?->code,
            'direction'           => $d->direction,
            'status'              => $d->status,
            'priority'            => $d->priority,
            'classification'      => $d->classification,
            'sender_name'         => $d->sender_name,
            'recipient_name'      => $d->recipient_name,
            'current_office_id'   => $d->current_office_id,
            'current_assignee_id' => $d->current_assignee_id,
            'received_at'         => $this->iso($d->received_at),
            'due_at'              => $this->iso($d->due_at),
            'created_at'          => $this->iso($d->created_at),
        ];
    }

    private function detail(Document $d): array
    {
        return $this->summary($d) + [
            'description'       => $d->description,
            'physical_location' => $d->physical_location,
            'origin_office_id'  => $d->origin_office_id,
            'closed_at'         => $this->iso($d->closed_at),
            'tags'              => $d->tags->pluck('name')->values(),
            'files'             => $d->files->map(fn ($f) => [
                'id' => $f->id, 'original_name' => $f->original_name, 'mime_type' => $f->mime_type,
                'size_bytes' => $f->size_bytes, 'sha256' => $f->sha256, 'version_no' => $f->version_no,
                'scan_status' => $f->scan_status, 'created_at' => $this->iso($f->created_at),
            ])->values(),
            'routes'            => $d->routes->map(fn ($x) => [
                'id' => $x->id, 'status' => $x->status, 'action_required' => $x->action_required,
                'from_office_id' => $x->from_office_id, 'from_user_id' => $x->from_user_id,
                'to_office_id' => $x->to_office_id, 'to_user_id' => $x->to_user_id,
                'due_at' => $this->iso($x->due_at), 'acknowledged_at' => $this->iso($x->acknowledged_at),
                'completed_at' => $this->iso($x->completed_at),
            ])->values(),
        ];
    }
}
