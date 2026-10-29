<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Services\WorkflowService;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class DocumentWorkflowController extends Controller
{
    public function decide(Request $r, int $id, WorkflowService $wf)
    {
        $doc  = Document::visibleTo($r->user())->findOrFail($id);
        $data = $r->validate([
            'decision' => ['required', Rule::in(['approved', 'rejected', 'returned'])],
            'reason'   => [Rule::requiredIf(in_array($r->input('decision'), ['rejected', 'returned'], true)), 'nullable', 'string', 'min:5', 'max:1000'],
            'route_id' => ['nullable', 'integer'],
        ]);

        return Api::ok($wf->decide($doc->id, $r->user(), $data['decision'], $data['reason'] ?? null, $data['route_id'] ?? null), 201);
    }

    public function release(Request $r, int $id, WorkflowService $wf)
    {
        $doc  = Document::visibleTo($r->user())->findOrFail($id);
        $data = $r->validate(['note' => ['nullable', 'string', 'max:500']]);
        return Api::ok($wf->state($wf->release($doc->id, $r->user(), $data['note'] ?? null)));
    }

    public function close(Request $r, int $id, WorkflowService $wf)
    {
        $doc  = Document::visibleTo($r->user())->findOrFail($id);
        $data = $r->validate(['note' => ['nullable', 'string', 'max:500']]);
        return Api::ok($wf->state($wf->close($doc->id, $r->user(), $data['note'] ?? null)));
    }

    public function reopen(Request $r, int $id, WorkflowService $wf)
    {
        $doc  = Document::visibleTo($r->user())->findOrFail($id);
        $data = $r->validate(['reason' => ['required', 'string', 'min:5', 'max:500']]);
        return Api::ok($wf->state($wf->reopen($doc->id, $r->user(), $data['reason'])));
    }
}
