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

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('question', 1000);
            $table->string('scope', 100)->default('default');
            $table->string('model', 100)->nullable();
            $table->string('provider', 50)->nullable();
            $table->string('conversation_id', 255)->nullable();
            $table->string('user_id', 100)->nullable();
            $table->string('status', 20)->default('success');
            $table->text('summary')->nullable();
            $table->text('steps');
            $table->string('error_message', 2000)->nullable();
            $table->string('error_class', 255)->nullable();
            $table->text('error_trace')->nullable();
            $table->float('duration_ms')->default(0);
            $table->unsignedSmallInteger('iterations')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('token_estimate')->default(0);
            $table->unsignedSmallInteger('tables_total')->nullable();
            $table->unsignedSmallInteger('tables_filtered')->nullable();
            $table->text('debug_queries');
            $table->timestamp('created_at')->nullable();

            $table->index('scope');
            $table->index('status');
            $table->index('user_id');
            $table->index('conversation_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $connection = config('laragrep.monitor.connection');
        $table = config('laragrep.monitor.table', 'laragrep_logs');

        Schema::connection($connection)->dropIfExists($table);
    }
};
