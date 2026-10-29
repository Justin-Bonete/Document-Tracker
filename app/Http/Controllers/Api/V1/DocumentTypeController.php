<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\DocumentType;
use App\Services\AuditLogger;
use App\Support\Api;
use Illuminate\Http\Request;

class DocumentTypeController extends Controller
{
    public function index()
    {
        return Api::ok(DocumentType::orderBy('name')->get(['id', 'code', 'name', 'retention_days']));
    }

    public function store(Request $r)
    {
        $data = $r->validate([
            'code'           => ['required', 'string', 'max:50', 'regex:/^[A-Z0-9_]+$/', 'unique:document_types,code'],
            'name'           => ['required', 'string', 'max:255'],
            'retention_days' => ['nullable', 'integer', 'min:1'],
        ]);
        $type = DocumentType::create($data);
        AuditLogger::record('document_type.create', 'document_type', $type->id, null, $data);
        return Api::ok($type->only(['id', 'code', 'name', 'retention_days']), 201);
    }
}
