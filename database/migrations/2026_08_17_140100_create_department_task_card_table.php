<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('department_task_card', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_card_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();

            // Почему задача попала в этот отдел: по исполнителю или по
            // соисполнителю. Различать нужно — «моя задача» и «участвую»
            // это разная нагрузка на отдел, и в отчётах их путать нельзя.
            $table->enum('source', ['responsible', 'accomplice', 'manual'])->default('responsible');

            $table->timestamps();

            $table->unique(['task_card_id', 'department_id', 'source']);
            $table->index(['portal_id', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('department_task_card');
    }
};
