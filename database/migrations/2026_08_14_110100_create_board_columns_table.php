<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('board_columns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('board_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('color', 16)->default('#94a3b8');
            $table->unsignedInteger('position');

            // Привязка к штатному статусу Битрикса (STATUS: 1..7).
            // Необязательная — в этом и смысл приложения: колонок может быть
            // больше, чем статусов, и лишние живут только у нас.
            $table->unsignedTinyInteger('bitrix_status')->nullable();

            // Колонка, куда попадают новые задачи, и колонка-финиш.
            $table->boolean('is_default')->default(false);
            $table->boolean('is_final')->default(false);

            // Предел незавершённой работы: 0 — без ограничения.
            $table->unsignedInteger('wip_limit')->default(0);

            $table->timestamps();

            $table->index(['portal_id', 'board_id', 'position']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('board_columns');
    }
};
