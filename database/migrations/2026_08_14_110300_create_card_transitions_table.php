<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_transitions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_card_id')->constrained()->cascadeOnDelete();

            $table->foreignId('from_column_id')->nullable()
                ->constrained('board_columns')->nullOnDelete();
            $table->foreignId('to_column_id')
                ->constrained('board_columns')->cascadeOnDelete();

            $table->foreignId('moved_by')->nullable()
                ->constrained('portal_users')->nullOnDelete();

            // Сколько карточка пролежала в предыдущей колонке. Считаем при
            // записи: восстанавливать это потом оконными функциями по всей
            // истории — дорого и на каждый отчёт заново.
            $table->unsignedBigInteger('seconds_in_previous')->nullable();

            $table->timestamp('created_at')->useCurrent();

            $table->index(['portal_id', 'task_card_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('card_transitions');
    }
};
