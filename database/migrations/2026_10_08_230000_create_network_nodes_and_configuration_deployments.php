<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('network_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('type', 40)->default('EDGE');
            $table->string('region', 80)->nullable();
            $table->string('endpoint', 255)->nullable();
            $table->string('status', 30)->default('PENDING');
            $table->string('version', 50)->nullable();
            $table->unsignedInteger('config_version')->nullable();
            $table->json('capabilities')->nullable();
            $table->string('credential_reference', 255)->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['network_id', 'name']);
            $table->index(['network_id', 'status']);
            $table->index(['network_id', 'last_seen_at']);
        });

        Schema::create('configuration_deployments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('network_id')->constrained('networks')->cascadeOnDelete();
            $table->foreignId('configuration_version_id')->constrained('configuration_versions')->cascadeOnDelete();
            $table->foreignId('network_node_id')->constrained('network_nodes')->cascadeOnDelete();
            $table->string('status', 30)->default('PENDING');
            $table->timestamp('requested_at');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();

            // Explicit short name: MySQL limits index identifiers to 64 characters.\n            $table->unique(\n                ['configuration_version_id', 'network_node_id'],\n                'cfg_deployments_config_node_unique'\n            );
            $table->index(['network_id', 'status']);
            $table->index(['network_node_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('configuration_deployments');
        Schema::dropIfExists('network_nodes');
    }
};
