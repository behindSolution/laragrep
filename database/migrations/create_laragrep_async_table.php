<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('laragrep.async.connection');
        $table = config('laragrep.async.table', 'laragrep_async');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->string('id', 36)->primary();
            $table->string('user_id')->nullable();
            $table->string('scope')->nullable();
            $table->text('question');
            $table->string('conversation_id')->nullable();
            $table->string('status', 20)->default('pending');
            $table->text('result')->nullable();
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();

            $table->index('status');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $connection = config('laragrep.async.connection');
        $table = config('laragrep.async.table', 'laragrep_async');

        Schema::connection($connection)->dropIfExists($table);
    }
};
