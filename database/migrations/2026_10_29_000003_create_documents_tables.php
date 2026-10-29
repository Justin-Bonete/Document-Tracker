<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('document_types', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name');
            $t->unsignedInteger('retention_days')->nullable();
            $t->timestamps();
        });

        Schema::create('documents', function (Blueprint $t) {
            $t->id();
            $t->string('tracking_no', 40)->unique();
            $t->string('title');
            $t->text('description')->nullable();
            $t->foreignId('document_type_id')->constrained('document_types');
            $t->string('direction', 20);                 // incoming|outgoing|internal
            $t->string('sender_name')->nullable();
            $t->string('recipient_name')->nullable();
            $t->foreignId('origin_office_id')->nullable()->constrained('offices');
            $t->foreignId('current_office_id')->nullable()->constrained('offices');
            $t->foreignId('current_assignee_id')->nullable()->constrained('users');
            $t->string('status', 30)->default('registered')->index();
            $t->string('priority', 20)->default('normal');
            $t->string('classification', 30)->default('internal')->index();
            $t->string('physical_location')->nullable();
            $t->timestamp('received_at')->nullable();
            $t->timestamp('due_at')->nullable()->index();
            $t->timestamp('closed_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
            $t->softDeletes();
            $t->string('void_reason')->nullable();
            $t->index(['current_office_id', 'status']);
        });

        Schema::create('tags', function (Blueprint $t) {
            $t->id();
            $t->string('name')->unique();
        });
        Schema::create('document_tags', function (Blueprint $t) {
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('tag_id')->constrained()->cascadeOnDelete();
            $t->primary(['document_id', 'tag_id']);
        });

        Schema::create('document_files', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->string('original_name');
            $t->string('storage_key')->unique();
            $t->string('mime_type', 120);
            $t->unsignedBigInteger('size_bytes');
            $t->char('sha256', 64);
            $t->unsignedSmallInteger('version_no')->default(1);
            $t->string('scan_status', 20)->default('pending_scan');
            $t->foreignId('uploaded_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('document_routes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('from_office_id')->nullable()->constrained('offices');
            $t->foreignId('from_user_id')->nullable()->constrained('users');
            $t->foreignId('to_office_id')->nullable()->constrained('offices');
            $t->foreignId('to_user_id')->nullable()->constrained('users');
            $t->string('action_required');
            $t->text('instructions')->nullable();
            $t->string('status', 30)->default('pending_dispatch')->index();
            $t->timestamp('assigned_at')->nullable();
            $t->timestamp('due_at')->nullable()->index();
            $t->timestamp('acknowledged_at')->nullable();
            $t->timestamp('completed_at')->nullable();
            $t->foreignId('created_by')->constrained('users');
            $t->timestamps();
        });

        Schema::create('route_events', function (Blueprint $t) {
            $t->id();
            $t->foreignId('route_id')->constrained('document_routes')->cascadeOnDelete();
            $t->string('event_type', 50);
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('from_status', 30)->nullable();
            $t->string('to_status', 30)->nullable();
            $t->text('comment')->nullable();
            $t->json('metadata_json')->nullable();
            $t->timestamp('occurred_at')->useCurrent();
        });

        Schema::create('document_decisions', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('route_id')->nullable()->constrained('document_routes')->nullOnDelete();
            $t->string('decision', 20);                  // approved|rejected|returned
            $t->text('reason')->nullable();
            $t->foreignId('decided_by')->constrained('users');
            $t->timestamp('decided_at')->useCurrent();
        });

        Schema::create('document_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignId('document_id')->constrained()->cascadeOnDelete();
            $t->foreignId('user_id')->constrained();
            $t->text('body');
            $t->string('visibility', 20)->default('internal');
            $t->timestamps();
        });
    }
    public function down(): void
    {
        foreach (['document_comments','document_decisions','route_events','document_routes','document_files','document_tags','tags','documents','document_types'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
