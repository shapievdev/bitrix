<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('portals', function (Blueprint $table) {
            // Коды созданных на портале пользовательских полей задач.
            // Имена фиксированные, но портал может их изменить при создании,
            // поэтому запоминаем то, что он вернул.
            $table->jsonb('task_user_fields')->nullable()->after('scope');
        });

        Schema::table('task_cards', function (Blueprint $table) {
            // Что мы в последний раз записали в поля задачи на портале.
            // Без этого пришлось бы переписывать все карточки при каждой
            // синхронизации: 276 задач — это 276 лишних вызовов REST.
            $table->jsonb('pushed_user_fields')->nullable()->after('fields');
        });
    }

    public function down(): void
    {
        Schema::table('portals', fn (Blueprint $table) => $table->dropColumn('task_user_fields'));
        Schema::table('task_cards', fn (Blueprint $table) => $table->dropColumn('pushed_user_fields'));
    }
};
