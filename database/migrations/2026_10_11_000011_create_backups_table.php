<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->string('filename', 150)->unique();
            $table->string('type', 20); // manual | scheduled | pre_restore | uploaded
            $table->string('status', 20); // running | completed | failed
            $table->unsignedBigInteger('size')->nullable();
            $table->string('checksum', 64)->nullable();
            $table->string('files_archive', 150)->nullable();
            $table->unsignedBigInteger('files_size')->nullable();
            $table->text('error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backups');
    }
};
