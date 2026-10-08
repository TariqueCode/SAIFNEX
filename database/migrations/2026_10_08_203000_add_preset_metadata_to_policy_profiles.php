<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->string('preset_key', 100)->nullable()->after('key');
            $table->unsignedBigInteger('template_profile_id')->nullable()->after('preset_key');
            $table->boolean('is_template')->default(false)->after('is_default');

            $table->index(['preset_key', 'is_template']);
            $table->foreign('template_profile_id')
                ->references('id')
                ->on('policy_profiles')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('policy_profiles', function (Blueprint $table) {
            $table->dropForeign(['template_profile_id']);
            $table->dropIndex(['preset_key', 'is_template']);
            $table->dropColumn(['preset_key', 'template_profile_id', 'is_template']);
        });
    }
};
