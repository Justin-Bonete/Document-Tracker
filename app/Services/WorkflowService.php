<?php
namespace App\Services;

use App\Exceptions\WorkflowException;
use App\Models\Document;
use App\Models\DocumentDecision;
use App\Models\DocumentRoute;
use App\Models\Office;
use App\Models\RouteEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The ONLY place that changes document / route status.
 * Every public method: one DB transaction, row locks (so two people can't act at once),
 * transition checks, a route_events row where relevant, and an audit event.
 *
 * Document: registered -> routed -> in_review -> approved | rejected | returned -> released -> closed
 * Route:    sent -> acknowledged -> completed | returned      (or sent -> cancelled)
 * Custody moves to the receiver when they ACKNOWLEDGE, and back to the sender on a decision or return.
 */
class WorkflowService
{
    private const ROUTABLE = ['registered', 'returned', 'in_review', 'approved'];
    private const ACTING   = ['acknowledged', 'in_progress'];

    // =============== ROUTING ===============

    public function dispatchRoute(int $docId, User $actor, array $d): DocumentRoute
    {
        return DB::transaction(fn () => $this->createRoute($this->lockDoc($docId), $actor, $d));
    }

    public function acknowledge(int $routeId, User $actor): DocumentRoute
    {
        return DB::transaction(function () use ($routeId, $actor) {
            $route = $this->lockRoute($routeId);
            $doc   = $this->lockDoc($route->document_id);

            if ($route->status !== 'sent') {
                throw new WorkflowException('INVALID_ROUTE_STATE', "Only routes in 'sent' status can be acknowledged.");
            }
            if (! $this->canReceive($actor, $route)) {
                throw new WorkflowException('NOT_RECIPIENT', 'You are not the recipient of this route.', 403);
            }

            $onBehalf   = $route->to_user_id !== null && ! self::same($route->to_user_id, $actor->id);
            $assigneeId = $route->to_user_id ?? $actor->id;     // an office-level route is "claimed" by whoever acknowledges it

            $route->update(['status' => 'acknowledged', 'acknowledged_at' => now(), 'to_user_id' => $assigneeId]);
            $this->event($route, $actor, 'acknowledged', 'sent', 'acknowledged', null, ['on_behalf' => $onBehalf]);

            $before = ['status' => $doc->status, 'current_office_id' => $doc->current_office_id, 'current_assignee_id' => $doc->current_assignee_id];
            $doc->update(['status' => 'in_review', 'current_office_id' => $route->to_office_id, 'current_assignee_id' => $assigneeId]);

            AuditLogger::record('route.acknowledge', 'document', $doc->id, $before, [
                'route_id' => $route->id, 'status' => 'in_review', 'current_office_id' => $route->to_office_id,
                'current_assignee_id' => $assigneeId, 'on_behalf' => $onBehalf,
            ]);
            return $route->refresh();
        });
    }

    public function complete(int $routeId, User $actor, ?string $comment): DocumentRoute
    {
        return DB::transaction(function () use ($routeId, $actor, $comment) {
            $route = $this->lockRoute($routeId);
            $doc   = $this->lockDoc($route->document_id);
            $this->requireActing($route, $actor);

            $old = $route->status;
            $route->update(['status' => 'completed', 'completed_at' => now()]);
            $this->event($route, $actor, 'completed', $old, 'completed', $comment);
            AuditLogger::record('route.complete', 'document', $doc->id, ['route_status' => $old], ['route_id' => $route->id, 'route_status' => 'completed']);
            return $route->refresh();
        });
    }

    /** Receiver sends the document back to the sender (no decision), with a reason. */
    public function returnRoute(int $routeId, User $actor, string $reason): DocumentRoute
    {
        return DB::transaction(function () use ($routeId, $actor, $reason) {
            $route = $this->lockRoute($routeId);
            $doc   = $this->lockDoc($route->document_id);
            $this->requireActing($route, $actor);

            $old = $route->status;
            $route->update(['status' => 'returned']);
            $this->event($route, $actor, 'returned', $old, 'returned', $reason);
            $doc->update(['status' => 'returned', 'current_office_id' => $route->from_office_id, 'current_assignee_id' => $route->from_user_id]);

            AuditLogger::record('route.return', 'document', $doc->id, ['status' => 'in_review'], [
                'route_id' => $route->id, 'status' => 'returned', 'reason' => $reason,
            ]);
            return $route->refresh();
        });
    }

    /** Receiver completes their step and passes the document on; returns the NEW route. */
    public function forward(int $routeId, User $actor, array $d): DocumentRoute
    {
        return DB::transaction(function () use ($routeId, $actor, $d) {
            $route = $this->lockRoute($routeId);
            $doc   = $this->lockDoc($route->document_id);
            $this->requireActing($route, $actor);

            $old = $route->status;
            $route->update(['status' => 'completed', 'completed_at' => now()]);
            $this->event($route, $actor, 'forwarded', $old, 'completed', null, [
                'to_office_id' => $d['to_office_id'], 'to_user_id' => $d['to_user_id'] ?? null,
            ]);
            $doc->update(['status' => 'in_review', 'current_office_id' => $route->to_office_id, 'current_assignee_id' => $actor->id]);

            return $this->createRoute($doc, $actor, $d);   // enforces the same target rules as a normal route
        });
    }

    /** Sender takes back a route nobody has acknowledged yet. */
    public function cancel(int $routeId, User $actor, string $reason): DocumentRoute
    {
        return DB::transaction(function () use ($routeId, $actor, $reason) {
            $route = $this->lockRoute($routeId);
            $doc   = $this->lockDoc($route->document_id);

            if ($route->status !== 'sent') {
                throw new WorkflowException('INVALID_ROUTE_STATE', 'Only routes that have not been acknowledged can be cancelled.');
            }
            $isSender = self::same($route->from_user_id, $actor->id)
                || self::same($route->created_by, $actor->id)
                || $this->inOffice($actor, $route->from_office_id);
            if (! $isSender) {
                throw new WorkflowException('NOT_SENDER', 'Only the sender can cancel this route.', 403);
            }

            $created = RouteEvent::where('route_id', $route->id)->where('event_type', 'created')->first();
            $restore = $created?->metadata_json['previous_document_status'] ?? 'registered';

            $route->update(['status' => 'cancelled']);
            $this->event($route, $actor, 'cancelled', 'sent', 'cancelled', $reason);
            if ($doc->status === 'routed') {
                $doc->update(['status' => $restore]);
            }
            AuditLogger::record('route.cancel', 'document', $doc->id, ['status' => 'routed'], [
                'route_id' => $route->id, 'status' => $doc->status, 'reason' => $reason,
            ]);
            return $route->refresh();
        });
    }

    // =============== DECISIONS ===============

    public function decide(int $docId, User $actor, string $decision, ?string $reason, ?int $routeId): array
    {
        return DB::transaction(function () use ($docId, $actor, $decision, $reason, $routeId) {
            $doc = $this->lockDoc($docId);

            $route = $routeId
                ? DocumentRoute::where('id', $routeId)->where('document_id', $doc->id)->lockForUpdate()->first()
                : DocumentRoute::where('document_id', $doc->id)->whereIn('status', self::ACTING)->lockForUpdate()->first();

            if (! $route || ! in_array($route->status, self::ACTING, true)) {
                throw new WorkflowException('NO_ACTIVE_ROUTE', 'A decision needs an acknowledged route assigned to you.');
            }
            if (! $this->canAct($actor, $route)) {
                throw new WorkflowException('NOT_ASSIGNEE', 'This route is not assigned to you.', 403);
            }
            if (in_array($decision, ['rejected', 'returned'], true) && trim((string) $reason) === '') {
                throw new WorkflowException('REASON_REQUIRED', 'A reason is required to reject or return a document.', 422);
            }

            $row = DocumentDecision::create([
                'document_id' => $doc->id, 'route_id' => $route->id, 'decision' => $decision,
                'reason' => $reason, 'decided_by' => $actor->id, 'decided_at' => now(),
            ]);

            $oldRoute  = $route->status;
            $newRoute  = $decision === 'returned' ? 'returned' : 'completed';
            $route->update(['status' => $newRoute] + ($newRoute === 'completed' ? ['completed_at' => now()] : []));
            $this->event($route, $actor, 'decision', $oldRoute, $newRoute, $reason, ['decision' => $decision]);

            // Decided documents go back to the sender's office for release / correction / closure.
            $before = ['status' => $doc->status, 'current_office_id' => $doc->current_office_id];
            $doc->update(['status' => $decision, 'current_office_id' => $route->from_office_id, 'current_assignee_id' => $route->from_user_id]);

            AuditLogger::record('decision.' . $decision, 'document', $doc->id, $before, [
                'decision_id' => $row->id, 'route_id' => $route->id, 'status' => $decision, 'reason' => $reason,
            ]);

            return [
                'decision_id' => $row->id,
                'decision'    => $decision,
                'reason'      => $reason,
                'route'       => ['id' => $route->id, 'status' => $newRoute],
                'document'    => $this->state($doc->refresh()),
            ];
        });
    }

    // =============== RELEASE / CLOSE / REOPEN ===============

    public function release(int $docId, User $actor, ?string $note): Document
    {
        return $this->documentStep($docId, $actor, 'release', ['approved'], 'released', true, $note);
    }

    public function close(int $docId, User $actor, ?string $note): Document
    {
        return $this->documentStep($docId, $actor, 'close', ['released', 'rejected'], 'closed', true, $note);
    }

    public function reopen(int $docId, User $actor, string $reason): Document
    {
        return $this->documentStep($docId, $actor, 'reopen', ['closed', 'archived', 'rejected', 'released'], 'registered', false, $reason);
    }

    private function documentStep(int $docId, User $actor, string $verb, array $from, string $to, bool $needsCustody, ?string $note): Document
    {
        return DB::transaction(function () use ($docId, $actor, $verb, $from, $to, $needsCustody, $note) {
            $doc = $this->lockDoc($docId);
            $this->requireDocStatus($doc, $from, $verb);

            if ($doc->routes()->whereIn('status', DocumentRoute::ACTIVE)->exists()) {
                throw new WorkflowException('ROUTE_ACTIVE', 'This document still has an active route.');
            }
            if ($needsCustody && ! $this->inOffice($actor, $doc->current_office_id)) {
                throw new WorkflowException('NOT_CUSTODIAN', 'Only the office currently holding the document can do this.', 403);
            }

            $before = ['status' => $doc->status, 'closed_at' => $doc->closed_at?->toIso8601ZuluString()];
            $doc->update([
                'status'    => $to,
                'closed_at' => $to === 'closed' ? now() : ($verb === 'reopen' ? null : $doc->closed_at),
            ]);
            AuditLogger::record('document.' . $verb, 'document', $doc->id, $before, ['status' => $to, 'note' => $note]);
            return $doc->refresh();
        });
    }

    // =============== INTERNALS ===============

    private function createRoute(Document $doc, User $actor, array $d): DocumentRoute
    {
        if ($doc->routes()->whereIn('status', DocumentRoute::ACTIVE)->exists()) {
            throw new WorkflowException('ROUTE_ACTIVE', 'This document already has an active route.');
        }
        $this->requireDocStatus($doc, self::ROUTABLE, 'route');

        if ($doc->routes()->whereIn('status', DocumentRoute::ACTIVE)->exists()) {
            throw new WorkflowException('ROUTE_ACTIVE', 'This document already has an active route.');
        }
        if (! $this->inOffice($actor, $doc->current_office_id)) {
            throw new WorkflowException('NOT_CUSTODIAN', 'Only the office currently holding the document can route it.', 403);
        }

        $office = Office::where('id', $d['to_office_id'])->where('active', true)->first();
        if (! $office) {
            throw new WorkflowException('INVALID_TARGET', 'The target office does not exist or is inactive.', 422);
        }

        $toUser = null;
        if (! empty($d['to_user_id'])) {
            $toUser = User::find($d['to_user_id']);
            if (! $toUser || ! $toUser->isActive() || ! $this->inOffice($toUser, $office->id)) {
                throw new WorkflowException('INVALID_TARGET', 'The selected person is not an active member of the target office.', 422);
            }
            if (self::same($toUser->id, $actor->id)) {
                throw new WorkflowException('INVALID_TARGET', 'You cannot route a document to yourself.', 422);
            }
            if ($doc->classification === 'confidential' && ! $toUser->hasPermission('documents.view_confidential')) {
                throw new WorkflowException('INVALID_TARGET', 'The selected person is not cleared for confidential documents.', 422);
            }
        } elseif (self::same($office->id, $doc->current_office_id)) {
            throw new WorkflowException('INVALID_TARGET', 'Choose a different office or a specific person.', 422);
        }

        $route = DocumentRoute::create([
            'document_id'     => $doc->id,
            'from_office_id'  => $doc->current_office_id,
            'from_user_id'    => $actor->id,
            'to_office_id'    => $office->id,
            'to_user_id'      => $toUser?->id,
            'action_required' => $d['action_required'],
            'instructions'    => $d['instructions'] ?? null,
            'status'          => 'sent',
            'assigned_at'     => now(),
            'due_at'          => DocumentRegistrar::utc($d['due_at'] ?? null),
            'created_by'      => $actor->id,
        ]);
        $this->event($route, $actor, 'created', null, 'sent', $d['instructions'] ?? null, ['previous_document_status' => $doc->status]);

        $prev = $doc->status;
        $doc->update(['status' => 'routed']);

        AuditLogger::record('route.create', 'document', $doc->id, ['status' => $prev], [
            'route_id' => $route->id, 'status' => 'routed', 'to_office_id' => $office->id,
            'to_user_id' => $toUser?->id, 'action_required' => $d['action_required'],
        ]);
        return $route;
    }

    private function requireActing(DocumentRoute $route, User $actor): void
    {
        if (! in_array($route->status, self::ACTING, true)) {
            throw new WorkflowException('INVALID_ROUTE_STATE', 'The route must be acknowledged before you can do this.');
        }
        if (! $this->canAct($actor, $route)) {
            throw new WorkflowException('NOT_ASSIGNEE', 'This route is not assigned to you.', 403);
        }
    }

    private function requireDocStatus(Document $doc, array $allowed, string $verb): void
    {
        if (! in_array($doc->status, $allowed, true)) {
            throw new WorkflowException('INVALID_TRANSITION', "Cannot {$verb} a document in status '{$doc->status}'.");
        }
    }

    private function lockDoc(int $id): Document
    {
        return Document::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function lockRoute(int $id): DocumentRoute
    {
        return DocumentRoute::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function event(DocumentRoute $route, User $actor, string $type, ?string $from, ?string $to, ?string $comment = null, array $meta = []): void
    {
        RouteEvent::create([
            'route_id' => $route->id, 'event_type' => $type, 'actor_user_id' => $actor->id,
            'from_status' => $from, 'to_status' => $to, 'comment' => $comment,
            'metadata_json' => $meta ?: null, 'occurred_at' => now(),
        ]);
    }

    public function inOffice(User $u, $officeId): bool
    {
        return $officeId !== null && $u->offices()->where('offices.id', $officeId)->exists();
    }

    /** May this user acknowledge the route? The named person, or an authorized receiver in the target office. */
    public function canReceive(User $u, DocumentRoute $r): bool
    {
        if (self::same($r->to_user_id, $u->id)) {
            return true;
        }
        if (! $this->inOffice($u, $r->to_office_id)) {
            return false;
        }
        return $r->to_user_id === null || $u->hasPermission('routes.receive_for_office');
    }

    /** May this user act on (complete / decide / return / forward) the route? Only the assignee. */
    public function canAct(User $u, DocumentRoute $r): bool
    {
        if (self::same($r->to_user_id, $u->id)) {
            return true;
        }
        return $r->to_user_id === null && $this->inOffice($u, $r->to_office_id);
    }

    public function state(Document $d): array
    {
        return [
            'id' => $d->id, 'tracking_no' => $d->tracking_no, 'status' => $d->status,
            'current_office_id' => $d->current_office_id, 'current_assignee_id' => $d->current_assignee_id,
            'closed_at' => $d->closed_at?->toIso8601ZuluString(),
        ];
    }

    private static function same($a, $b): bool
    {
        return $a !== null && $b !== null && (int) $a === (int) $b;
    }
}

