<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('offices', function (Blueprint $t) {
            $t->id();
            $t->string('office_code', 30)->unique();
            $t->string('name');
            $t->foreignId('parent_office_id')->nullable()->constrained('offices')->nullOnDelete();
            $t->boolean('active')->default(true);
            $t->timestamps();
        });

        Schema::create('roles', function (Blueprint $t) {
            $t->id();
            $t->string('code', 50)->unique();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('permissions', function (Blueprint $t) {
            $t->id();
            $t->string('code', 80)->unique();
            $t->string('name');
            $t->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $t) {
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('permission_id')->constrained()->cascadeOnDelete();
            $t->primary(['role_id', 'permission_id']);
        });

        // office_id = optional scope of the role (null = all offices)
        Schema::create('user_roles', function (Blueprint $t) {
            $t->id();
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('role_id')->constrained()->cascadeOnDelete();
            $t->foreignId('office_id')->nullable()->constrained()->nullOnDelete();
            $t->unique(['user_id', 'role_id', 'office_id']);
        });

        Schema::create('office_user', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained()->cascadeOnDelete();
            $t->foreignId('office_id')->constrained()->cascadeOnDelete();
            $t->primary(['user_id', 'office_id']);
        });
    }
    public function down(): void
    {
        foreach (['office_user', 'user_roles', 'role_permissions', 'permissions', 'roles', 'offices'] as $tbl) {
            Schema::dropIfExists($tbl);
        }
    }
};
