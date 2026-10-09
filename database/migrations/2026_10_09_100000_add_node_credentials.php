<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('network_nodes', function (Blueprint $table) {
            $table->char('credential_hash', 64)->nullable()->unique('network_nodes_credential_hash_unique');
            $table->timestamp('credential_rotated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('network_nodes', function (Blueprint $table) {
            $table->dropUnique('network_nodes_credential_hash_unique');
            $table->dropColumn(['credential_hash', 'credential_rotated_at']);
        });
    }
};
