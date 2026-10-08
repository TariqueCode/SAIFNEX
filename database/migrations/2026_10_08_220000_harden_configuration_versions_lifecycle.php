<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('configuration_versions', function (Blueprint $table) {
            $table->timestamp('validated_at')->nullable()->after('generated_at');
            $table->timestamp('staged_at')->nullable()->after('published_at');
            $table->timestamp('activated_at')->nullable()->after('staged_at');
            $table->string('signature', 255)->nullable()->after('snapshot_hash');
            $table->string('signature_algorithm', 50)->nullable()->after('signature');
            $table->text('error_message')->nullable()->after('activated_at');
        });
    }

    public function down(): void
    {
        Schema::table('configuration_versions', function (Blueprint $table) {
            $table->dropColumn([
                'validated_at',
                'staged_at',
                'activated_at',
                'signature',
                'signature_algorithm',
                'error_message',
            ]);
        });
    }
};
