<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentRoute;
use App\Services\WorkflowService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class RouteController extends Controller
{
    private const STATUSES = ['pending_dispatch', 'sent', 'acknowledged', 'in_progress', 'completed', 'returned', 'cancelled', 'escalated'];

    /** GET /routes?box=inbox|outbox : my work queue. */
    public function index(Request $r)
    {
        $f = $r->validate([
            'box'      => ['required', Rule::in(['inbox', 'outbox'])],
            'status'   => ['nullable', Rule::in(self::STATUSES)],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
        $u = $r->user();

        $q = DocumentRoute::query()
            ->with('document:id,tracking_no,title,status,priority,classification')
            ->whereIn('document_id', Document::visibleTo($u)->select('documents.id'));

        if ($f['box'] === 'inbox') {
            $offices   = $u->offices()->pluck('offices.id')->all();
            $forOffice = $u->hasPermission('routes.receive_for_office');
            $q->where(function ($w) use ($u, $offices, $forOffice) {
                $w->where('to_user_id', $u->id)
                  ->orWhere(fn ($x) => $x->whereNull('to_user_id')->whereIn('to_office_id', $offices));
                if ($forOffice) {
                    $w->orWhereIn('to_office_id', $offices);
                }
            });
            $q->whereIn('status', isset($f['status']) ? [$f['status']] : DocumentRoute::ACTIVE);
        } else {
            $q->where(fn ($w) => $w->where('from_user_id', $u->id)->orWhere('created_by', $u->id));
            if (isset($f['status'])) {
                $q->where('status', $f['status']);
            }
        }

        $p = $q->orderByRaw('due_at IS NULL')->orderBy('due_at')->orderByDesc('id')->paginate($f['per_page'] ?? 20);

        return Api::ok(
            $p->getCollection()->map(fn ($x) => self::present($x))->values(),
            200,
            ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        );
    }

    /** GET /documents/{id}/routes : full custody timeline. */
    public function forDocument(Request $r, int $id)
    {
        $doc = Document::visibleTo($r->user())->findOrFail($id);
        $routes = DocumentRoute::where('document_id', $doc->id)->with('events.actor:id,name')->orderBy('id')->get();

        return Api::ok($routes->map(function ($x) {
            return self::present($x) + ['events' => $x->events->map(fn ($e) => [
                'id'          => $e->id,
                'event_type'  => $e->event_type,
                'actor'       => $e->actor ? ['id' => $e->actor->id, 'name' => $e->actor->name] : null,
                'from_status' => $e->from_status,
                'to_status'   => $e->to_status,
                'comment'     => $e->comment,
                'metadata'    => $e->metadata_json,
                'occurred_at' => $e->occurred_at?->toIso8601ZuluString(),
            ])->values()];
        })->values());
    }

    public function store(Request $r, int $id, WorkflowService $wf)
    {
        $doc   = Document::visibleTo($r->user())->findOrFail($id);
        $route = $wf->dispatchRoute($doc->id, $r->user(), $this->validateTarget($r));
        return Api::ok(self::present($route), 201);
    }

    public function acknowledge(Request $r, int $routeId, WorkflowService $wf)
    {
        $route = $this->find($r, $routeId);
        return Api::ok(self::present($wf->acknowledge($route->id, $r->user())));
    }

    public function complete(Request $r, int $routeId, WorkflowService $wf)
    {
        $data  = $r->validate(['comment' => ['nullable', 'string', 'max:2000']]);
        $route = $this->find($r, $routeId);
        return Api::ok(self::present($wf->complete($route->id, $r->user(), $data['comment'] ?? null)));
    }

    public function returnRoute(Request $r, int $routeId, WorkflowService $wf)
    {
        $data  = $r->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $route = $this->find($r, $routeId);
        return Api::ok(self::present($wf->returnRoute($route->id, $r->user(), $data['reason'])));
    }

    public function forward(Request $r, int $routeId, WorkflowService $wf)
    {
        $data  = $this->validateTarget($r);
        $route = $this->find($r, $routeId);
        return Api::ok(self::present($wf->forward($route->id, $r->user(), $data)), 201);
    }

    public function cancel(Request $r, int $routeId, WorkflowService $wf)
    {
        $data  = $r->validate(['reason' => ['required', 'string', 'min:5', 'max:1000']]);
        $route = $this->find($r, $routeId);
        return Api::ok(self::present($wf->cancel($route->id, $r->user(), $data['reason'])));
    }

    // ---------- helpers ----------
    /** A route is only reachable if its document is visible to the user (otherwise 404). */
    private function find(Request $r, int $id): DocumentRoute
    {
        return DocumentRoute::whereIn('document_id', Document::visibleTo($r->user())->select('documents.id'))->findOrFail($id);
    }

    private function validateTarget(Request $r): array
    {
        return $r->validate([
            'to_office_id'    => ['required', 'integer', 'exists:offices,id'],
            'to_user_id'      => ['nullable', 'integer', 'exists:users,id'],
            'action_required' => ['required', 'string', 'max:255'],
            'instructions'    => ['nullable', 'string', 'max:2000'],
            'due_at'          => ['nullable', 'date', 'after:now'],
        ]);
    }

    public static function present(DocumentRoute $x): array
    {
        $iso = fn ($d) => $d?->toIso8601ZuluString();
        $out = [
            'id'              => $x->id,
            'document_id'     => $x->document_id,
            'status'          => $x->status,
            'action_required' => $x->action_required,
            'instructions'    => $x->instructions,
            'from_office_id'  => $x->from_office_id,
            'from_user_id'    => $x->from_user_id,
            'to_office_id'    => $x->to_office_id,
            'to_user_id'      => $x->to_user_id,
            'assigned_at'     => $iso($x->assigned_at),
            'due_at'          => $iso($x->due_at),
            'acknowledged_at' => $iso($x->acknowledged_at),
            'completed_at'    => $iso($x->completed_at),
            'is_overdue'      => (bool) ($x->due_at && $x->due_at->isPast() && in_array($x->status, DocumentRoute::ACTIVE, true)),
        ];
        if ($x->relationLoaded('document') && $x->document) {
            $out['document'] = $x->document->only(['id', 'tracking_no', 'title', 'status', 'priority', 'classification']);
        }
        return $out;
    }
}
