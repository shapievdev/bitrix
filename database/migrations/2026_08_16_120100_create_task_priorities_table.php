<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_priorities', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('color', 16)->default('#64748b');

            // Чем больше вес, тем выше приоритет. Числом, а не порядком в
            // списке: по нему сортируются карточки внутри ячейки, и вставка
            // нового уровня между существующими не должна всё перенумеровывать.
            $table->unsignedInteger('weight')->default(0);

            // Штатный PRIORITY Битрикса (0 низкий, 1 средний, 2 высокий).
            // Своих уровней может быть больше трёх, поэтому связь
            // необязательная и допускает несколько наших на один штатный.
            $table->unsignedTinyInteger('bitrix_priority')->nullable();

            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['portal_id', 'weight']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_priorities');
    }
};
