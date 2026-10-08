<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_rules', function (Blueprint $table) {
            $table->foreignId('schedule_id')
                ->nullable()
                ->after('profile_id')
                ->constrained('schedules')
                ->nullOnDelete();

            $table->index(['schedule_id', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::table('policy_rules', function (Blueprint $table) {
            $table->dropForeign(['schedule_id']);
            $table->dropIndex(['schedule_id', 'enabled']);
            $table->dropColumn('schedule_id');
        });
    }
};
