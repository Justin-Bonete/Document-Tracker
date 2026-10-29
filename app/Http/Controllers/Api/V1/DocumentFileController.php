<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Document;
use App\Models\DocumentFile;
use App\Services\AuditLogger;
use App\Support\Api;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DocumentFileController extends Controller
{
    public function store(Request $r, int $id)
    {
        $doc = Document::visibleTo($r->user())->findOrFail($id);

        if (in_array($doc->status, Document::LOCKED, true)) {
            return Api::error('DOCUMENT_LOCKED', 'Closed, archived or voided documents are read-only.', 409);
        }

        $r->validate([
            'file' => [
                'required', 'file',
                'max:' . config('dts.upload.max_kb'),
                'mimes:' . implode(',', config('dts.upload.extensions')),   // checks real content type, not just the name
            ],
        ]);

        $upload = $r->file('file');
        $name   = Str::limit(preg_replace('/[\x00-\x1F\x7F]/', '', basename($upload->getClientOriginalName())), 255, '');
        $uuid   = (string) Str::uuid();
        $dir    = "documents/{$doc->id}";
        $key    = "$dir/$uuid";                       // opaque key: no user-controlled name or extension on disk
        $sha    = hash_file('sha256', $upload->getRealPath());
        $mime   = $upload->getMimeType() ?: 'application/octet-stream';
        $size   = $upload->getSize();

        Storage::disk('local')->putFileAs($dir, $upload, $uuid);   // private disk: storage/app/private

        try {
            $file = DB::transaction(function () use ($doc, $r, $name, $key, $mime, $size, $sha) {
                $version = (int) DocumentFile::where('document_id', $doc->id)->where('original_name', $name)->max('version_no') + 1;

                $file = DocumentFile::create([
                    'document_id' => $doc->id, 'original_name' => $name, 'storage_key' => $key,
                    'mime_type' => $mime, 'size_bytes' => $size, 'sha256' => $sha,
                    'version_no' => $version, 'scan_status' => 'pending_scan', 'uploaded_by' => $r->user()->id,
                ]);

                AuditLogger::record('file.upload', 'document', $doc->id, null, [
                    'file_id' => $file->id, 'name' => $name, 'version_no' => $version, 'sha256' => $sha, 'size_bytes' => $size,
                ]);
                return $file;
            });
        } catch (\Throwable $e) {
            Storage::disk('local')->delete($key);     // don't leave an orphan file behind
            throw $e;
        }

        return Api::ok([
            'id' => $file->id, 'original_name' => $file->original_name, 'version_no' => $file->version_no,
            'sha256' => $file->sha256, 'scan_status' => $file->scan_status,
        ], 201);
    }

    public function download(Request $r, int $id, int $fileId)
    {
        $doc  = Document::visibleTo($r->user())->findOrFail($id);   // same access rule as viewing the document
        $file = DocumentFile::where('document_id', $doc->id)->findOrFail($fileId);

        $cleared = $file->scan_status === 'clean'
            || (! config('dts.malware_scan_enabled') && $file->scan_status === 'pending_scan');
        if (! $cleared) {
            return Api::error('FILE_NOT_AVAILABLE', 'This file has not passed the security scan.', 409);
        }
        if (! Storage::disk('local')->exists($file->storage_key)) {
            return Api::error('FILE_MISSING', 'The stored file could not be found.', 404);
        }

        AuditLogger::record('file.download', 'document', $doc->id, null, [
            'file_id' => $file->id, 'name' => $file->original_name, 'sha256' => $file->sha256,
        ]);

        return Storage::disk('local')->download($file->storage_key, $file->original_name, [
            'Content-Type'           => 'application/octet-stream',   // force download, never render in browser
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
