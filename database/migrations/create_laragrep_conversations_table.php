<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('laragrep.conversation.connection');
        $table = config('laragrep.conversation.table', 'laragrep_conversations');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('context', 255);
            $table->string('role', 32);
            $table->text('content');
            $table->timestamp('created_at')->nullable();

            $table->index('context');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $connection = config('laragrep.conversation.connection');
        $table = config('laragrep.conversation.table', 'laragrep_conversations');

        Schema::connection($connection)->dropIfExists($table);
    }
};
