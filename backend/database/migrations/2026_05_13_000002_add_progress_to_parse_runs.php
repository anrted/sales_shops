<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('parse_runs', function (Blueprint $table): void {
            $table->string('current_step')->nullable()->after('error_message');
            $table->timestamp('heartbeat_at')->nullable()->after('current_step');
        });
    }

    public function down(): void
    {
        Schema::table('parse_runs', function (Blueprint $table): void {
            $table->dropColumn(['current_step', 'heartbeat_at']);
        });
    }
};
