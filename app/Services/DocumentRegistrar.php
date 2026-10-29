<?php
namespace App\Services;

use App\Models\Document;
use App\Models\DocumentType;
use App\Models\Tag;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class DocumentRegistrar
{
    /** Parse any ISO date (with offset) and normalise to UTC so we store UTC consistently. */
    public static function utc(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value)->utc() : null;
    }

    public static function syncTags(Document $doc, array $names): void
    {
        $ids = collect($names)
            ->map(fn ($n) => mb_strtolower(trim($n)))
            ->filter()->unique()
            ->map(fn ($n) => Tag::firstOrCreate(['name' => $n])->id)
            ->all();
        $doc->tags()->sync($ids);
    }

    public function register(array $data, User $user): Document
    {
        return DB::transaction(function () use ($data, $user) {
            $type = DocumentType::where('code', $data['document_type_code'])->firstOrFail();

            // Insert with a throwaway unique value, then derive the real number from the row id.
            // The id comes from the database, so two users can never get the same tracking number.
            $doc = Document::create([
                'tracking_no'       => 'TMP-' . Str::uuid(),
                'title'             => $data['title'],
                'description'       => $data['description'] ?? null,
                'document_type_id'  => $type->id,
                'direction'         => $data['direction'],
                'sender_name'       => $data['sender_name'] ?? null,
                'recipient_name'    => $data['recipient_name'] ?? null,
                'origin_office_id'  => $data['origin_office_id'],
                'current_office_id' => $data['origin_office_id'],
                'status'            => 'registered',
                'priority'          => $data['priority'] ?? 'normal',
                'classification'    => $data['classification'] ?? 'internal',
                'physical_location' => $data['physical_location'] ?? null,
                'received_at'       => self::utc($data['received_at'] ?? null) ?? now(),
                'due_at'            => self::utc($data['due_at'] ?? null),
                'created_by'        => $user->id,
            ]);

            $doc->update([
                'tracking_no' => sprintf('%s-%d-%07d', config('dts.tracking_prefix'), now()->year, $doc->id),
            ]);

            if (! empty($data['tags'])) {
                self::syncTags($doc, $data['tags']);
            }

            AuditLogger::record('document.create', 'document', $doc->id, null, [
                'tracking_no'    => $doc->tracking_no,
                'title'          => $doc->title,
                'status'         => $doc->status,
                'classification' => $doc->classification,
                'origin_office_id' => $doc->origin_office_id,
            ]);

            return $doc;
        });
    }
}
