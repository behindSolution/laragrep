<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('laragrep.recipes.connection');
        $table = config('laragrep.recipes.table', 'laragrep_recipes');

        Schema::connection($connection)->create($table, function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('conversation_id', 255)->nullable();
            $table->string('user_id')->nullable();
            $table->string('scope', 100)->default('default');
            $table->string('question', 1000);
            $table->text('summary')->nullable();
            $table->text('recipe');
            $table->timestamp('created_at')->nullable();

            $table->index('conversation_id');
            $table->index('user_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $connection = config('laragrep.recipes.connection');
        $table = config('laragrep.recipes.table', 'laragrep_recipes');

        Schema::connection($connection)->dropIfExists($table);
    }
};
