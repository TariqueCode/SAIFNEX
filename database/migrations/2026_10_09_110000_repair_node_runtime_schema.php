<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Repair migration history/schema drift without dropping existing data.
        if (!Schema::hasTable('network_nodes')) {
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
                $table->char('credential_hash', 64)->nullable();
                $table->timestamp('credential_rotated_at')->nullable();
                $table->timestamp('last_seen_at')->nullable();
                $table->timestamps();

                $table->unique(['network_id', 'name']);
                $table->unique('credential_hash', 'network_nodes_credential_hash_unique');
                $table->index(['network_id', 'status']);
                $table->index(['network_id', 'last_seen_at']);
            });
        } else {
            Schema::table('network_nodes', function (Blueprint $table) {
                if (!Schema::hasColumn('network_nodes', 'credential_hash')) {
                    $table->char('credential_hash', 64)->nullable();
                    $table->unique('credential_hash', 'network_nodes_credential_hash_unique');
                }

                if (!Schema::hasColumn('network_nodes', 'credential_rotated_at')) {
                    $table->timestamp('credential_rotated_at')->nullable();
                }
            });
        }

        if (!Schema::hasTable('configuration_deployments')) {
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

                $table->unique(
                    ['configuration_version_id', 'network_node_id'],
                    'cfg_deployments_config_node_unique'
                );
                $table->index(['network_id', 'status']);
                $table->index(['network_node_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        // Intentionally non-destructive: this migration repairs drift and may preserve live data.
    }
};
