<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_cards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_column_id')->constrained()->cascadeOnDelete();

            $table->unsignedBigInteger('bitrix_task_id');
            $table->unsignedInteger('position');

            // Копия полей задачи. Доска на 200 карточек не может дёргать REST
            // на каждую отрисовку — лимит в 2 запроса в секунду это убьёт.
            // Источник истины остаётся на портале, здесь — витрина.
            $table->string('title');
            $table->unsignedBigInteger('responsible_id')->nullable();
            $table->unsignedBigInteger('creator_id')->nullable();
            $table->unsignedTinyInteger('bitrix_status')->nullable();
            $table->unsignedTinyInteger('priority')->nullable();
            $table->timestamp('deadline')->nullable();
            $table->timestamp('closed_at')->nullable();

            // Поля, которые понадобятся позже (теги, чек-листы, доп. поля),
            // без миграции под каждое.
            $table->jsonb('fields')->nullable();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            // Одна задача — одна карточка на доске.
            $table->unique(['board_id', 'bitrix_task_id']);
            $table->index(['portal_id', 'bitrix_task_id']);
            $table->index(['board_column_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_cards');
    }
};
