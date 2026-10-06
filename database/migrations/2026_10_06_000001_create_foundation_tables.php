<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->nullable()->after('email');
            $table->boolean('is_active')->default(true)->after('password');
            $table->timestamp('last_login_at')->nullable();
            $table->softDeletes();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('group', 50)->index();
            $table->string('key', 100)->unique();
            $table->jsonb('value')->nullable();
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('event', 60);
            $table->nullableMorphs('auditable');
            $table->string('description')->nullable();
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->text('reason')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['event', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('document_sequences', function (Blueprint $table) {
            $table->id();
            $table->string('key', 50)->unique();
            $table->string('prefix', 20);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->unsignedSmallInteger('padding')->default(6);
            $table->timestamps();
        });

        DB::statement('ALTER TABLE document_sequences ADD CONSTRAINT document_sequences_next_positive CHECK (next_number > 0)');
    }

    public function down(): void
    {
        Schema::dropIfExists('document_sequences');
        Schema::dropIfExists('audit_logs');
        Schema::dropIfExists('settings');
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['phone', 'is_active', 'last_login_at', 'deleted_at']);
        });
    }
};
