<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('idempotency_keys', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->string('idem_key', 100);
            $t->char('request_hash', 64);
            $t->unsignedSmallInteger('response_status')->nullable();   // null = still processing
            $t->longText('response_body')->nullable();
            $t->timestamp('created_at')->useCurrent()->index();
            $t->unique(['user_id', 'idem_key']);
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('idempotency_keys');
    }
};
