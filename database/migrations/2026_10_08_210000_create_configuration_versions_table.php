<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('configuration_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 30)->default('GENERATED');
            $table->string('schema_version', 30)->default('1');
            $table->json('snapshot');
            $table->string('snapshot_hash', 64);
            $table->timestamp('generated_at');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['network_id', 'version']);
            $table->unique(['network_id', 'snapshot_hash']);
            $table->index(['network_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_versions');
    }
};
