<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A later repair migration recreates these tables if migration history and schema diverge.
        if (!Schema::hasTable('network_nodes')) {
            return;
        }

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

    public function down(): void
    {
        if (!Schema::hasTable('network_nodes')) {
            return;
        }

        Schema::table('network_nodes', function (Blueprint $table) {
            if (Schema::hasColumn('network_nodes', 'credential_hash')) {
                $table->dropUnique('network_nodes_credential_hash_unique');
                $table->dropColumn('credential_hash');
            }

            if (Schema::hasColumn('network_nodes', 'credential_rotated_at')) {
                $table->dropColumn('credential_rotated_at');
            }
        });
    }
};
