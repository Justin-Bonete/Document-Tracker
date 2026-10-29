<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('notifications_queue', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            $t->string('channel', 20);                   // in_app|email|sms
            $t->string('template_key', 80);
            $t->string('dedupe_key')->unique();          // e.g. route:12:due_soon:3d
            $t->string('status', 20)->default('queued')->index();
            $t->timestamp('scheduled_at')->nullable()->index();
            $t->timestamp('sent_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });

        Schema::create('notification_attempts', function (Blueprint $t) {
            $t->id();
            $t->foreignId('notification_id')->constrained('notifications_queue')->cascadeOnDelete();
            $t->unsignedSmallInteger('attempt_no');
            $t->string('provider_message_id')->nullable();
            $t->string('result', 20);
            $t->text('error_text')->nullable();
            $t->timestamp('attempted_at')->useCurrent();
        });

        Schema::create('audit_logs', function (Blueprint $t) {
            $t->id();
            $t->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $t->string('action', 80)->index();
            $t->string('entity_type', 60)->nullable();
            $t->unsignedBigInteger('entity_id')->nullable();
            $t->string('request_id', 40)->nullable()->index();
            $t->string('ip_address', 45)->nullable();
            $t->string('user_agent', 255)->nullable();
            $t->json('before_json')->nullable();
            $t->json('after_json')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->index(['entity_type', 'entity_id']);
        });
    }
    public function down(): void
    {
        foreach (['audit_logs', 'notification_attempts', 'notifications_queue'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
