<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('key', 100);
            $table->string('source', 30)->default('CUSTOM');
            $table->string('status', 30)->default('DRAFT');
            $table->text('description')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->unique(['network_id', 'key']);
            $table->index(['network_id', 'status']);
        });

        Schema::create('policy_rules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('policy_profiles')->cascadeOnDelete();
            $table->string('target_type', 40);
            $table->string('target', 255);
            $table->string('action', 30);
            $table->unsignedInteger('priority')->default(1000);
            $table->boolean('enabled')->default(true);
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['profile_id', 'enabled', 'priority']);
            $table->index(['target_type', 'target']);
        });

        Schema::create('schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('timezone', 64)->default('UTC');
            $table->json('definition');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['network_id', 'enabled']);
        });

        Schema::create('device_policy_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('profile_id')->constrained('policy_profiles')->cascadeOnDelete();
            $table->foreignId('assigned_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['device_id', 'profile_id']);
            $table->index(['device_id', 'starts_at', 'expires_at']);
        });

        Schema::create('policy_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('policy_profiles')->cascadeOnDelete();
            $table->unsignedInteger('version');
            $table->string('status', 30)->default('DRAFT');
            $table->json('snapshot')->nullable();
            $table->string('snapshot_hash', 64)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'version']);
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('policy_versions');
        Schema::dropIfExists('device_policy_assignments');
        Schema::dropIfExists('schedules');
        Schema::dropIfExists('policy_rules');
        Schema::dropIfExists('policy_profiles');
    }
};
