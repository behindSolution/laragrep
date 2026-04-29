<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('laragrep.monitor.connection');
        $table = config('laragrep.monitor.table', 'laragrep_logs');

        if (!Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (Schema::connection($connection)->hasColumn($table, 'metadata')) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->text('metadata')->nullable()->after('debug_queries');
        });
    }

    public function down(): void
    {
        $connection = config('laragrep.monitor.connection');
        $table = config('laragrep.monitor.table', 'laragrep_logs');

        if (!Schema::connection($connection)->hasTable($table)) {
            return;
        }

        if (!Schema::connection($connection)->hasColumn($table, 'metadata')) {
            return;
        }

        Schema::connection($connection)->table($table, function (Blueprint $table) {
            $table->dropColumn('metadata');
        });
    }
};
