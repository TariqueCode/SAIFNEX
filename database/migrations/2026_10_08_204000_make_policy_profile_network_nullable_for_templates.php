<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->dropForeign(['network_id']);
        });

        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->foreignId('network_id')->nullable()->change();
        });

        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->foreign('network_id')->references('id')->on('networks')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->dropForeign(['network_id']);
        });

        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->foreignId('network_id')->nullable(false)->change();
        });

        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->foreign('network_id')->references('id')->on('networks')->cascadeOnDelete();
        });
    }
};
