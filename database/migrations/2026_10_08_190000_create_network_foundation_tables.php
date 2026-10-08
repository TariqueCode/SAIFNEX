<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->boolean('is_system')->default(true);
            $table->timestamps();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 150)->unique();
            $table->string('name', 150);
            $table->string('description')->nullable();
            $table->timestamps();
        });

        Schema::create('role_permissions', function (Blueprint $table) {
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained('permissions')->cascadeOnDelete();
            $table->primary(['role_id', 'permission_id']);
        });

        Schema::create('networks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 40)->default('HOME');
            $table->string('status', 30)->default('ACTIVE');
            $table->string('timezone', 64)->default('UTC');
            $table->text('description')->nullable();
            $table->timestamps();
            $table->index(['owner_id', 'status']);
        });

        Schema::create('network_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('role_id')->constrained('roles')->restrictOnDelete();
            $table->string('status', 30)->default('ACTIVE');
            $table->timestamp('joined_at')->nullable();
            $table->timestamps();
            $table->unique(['network_id', 'user_id']);
            $table->index(['user_id', 'status']);
        });

        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 50)->default('UNKNOWN');
            $table->string('identifier', 191);
            $table->string('profile_key', 100)->nullable();
            $table->string('status', 30)->default('PENDING');
            $table->json('capabilities')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
            $table->unique(['network_id', 'identifier']);
            $table->index(['network_id', 'status']);
        });

        Schema::create('device_enrollments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->string('token_hash', 64)->unique();
            $table->string('status', 30)->default('PENDING');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();
            $table->index(['network_id', 'status']);
        });

        Schema::create('device_credentials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained('devices')->cascadeOnDelete();
            $table->string('type', 40);
            $table->string('fingerprint', 191)->unique();
            $table->text('public_key')->nullable();
            $table->string('status', 30)->default('ACTIVE');
            $table->timestamp('issued_at');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['device_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_credentials');
        Schema::dropIfExists('device_enrollments');
        Schema::dropIfExists('devices');
        Schema::dropIfExists('network_members');
        Schema::dropIfExists('networks');
        Schema::dropIfExists('role_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('roles');
    }
};
