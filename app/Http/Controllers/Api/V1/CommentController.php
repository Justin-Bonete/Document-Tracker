<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentComment;
use App\Services\AuditLogger;
use App\Support\Api;
use Illuminate\Http\Request;

class CommentController extends Controller
{
    public function index(Request $r, int $id)
    {
        $doc = Document::visibleTo($r->user())->findOrFail($id);
        $rows = DocumentComment::with('author:id,name')->where('document_id', $doc->id)->orderBy('id')->get();
        return Api::ok($rows->map(fn ($c) => $this->present($c))->values());
    }

    public function store(Request $r, int $id)
    {
        $doc  = Document::visibleTo($r->user())->findOrFail($id);
        $data = $r->validate(['body' => ['required', 'string', 'max:5000']]);

        if (in_array($doc->status, Document::LOCKED, true)) {
            return Api::error('DOCUMENT_LOCKED', 'Closed, archived or voided documents are read-only.', 409);
        }

        $c = DocumentComment::create(['document_id' => $doc->id, 'user_id' => $r->user()->id, 'body' => $data['body'], 'visibility' => 'internal']);
        AuditLogger::record('comment.create', 'document', $doc->id, null, ['comment_id' => $c->id]);   // body is not copied into the audit log
        return Api::ok($this->present($c->load('author:id,name')), 201);
    }

    private function present(DocumentComment $c): array
    {
        return [
            'id'         => $c->id,
            'author'     => $c->author ? ['id' => $c->author->id, 'name' => $c->author->name] : null,
            'body'       => $c->body,
            'created_at' => $c->created_at?->toIso8601ZuluString(),
        ];
    }
}
