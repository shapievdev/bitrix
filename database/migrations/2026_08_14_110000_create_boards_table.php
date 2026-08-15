<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('boards', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('description')->nullable();

            // Доска может быть привязана к рабочей группе (проекту) портала
            // либо быть свободной — тогда состав задач задаёт только фильтр.
            $table->unsignedBigInteger('bitrix_group_id')->nullable();

            // Фильтр в формате tasks.task.list: именно он определяет, какие
            // задачи попадают на доску. Держим as-is, чтобы не пересобирать
            // язык фильтров поверх готового.
            $table->jsonb('filter')->nullable();

            $table->foreignId('created_by')->nullable()
                ->constrained('portal_users')->nullOnDelete();

            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->index(['portal_id', 'bitrix_group_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('boards');
    }
};
