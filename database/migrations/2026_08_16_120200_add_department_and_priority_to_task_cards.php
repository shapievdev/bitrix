<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            // null — дорожка «Без подразделения»: задача есть, отдел
            // определить не удалось. Прятать такие задачи нельзя, иначе
            // они потеряются молча.
            $table->foreignId('department_id')->nullable()->after('board_column_id')
                ->constrained()->nullOnDelete();

            $table->foreignId('task_priority_id')->nullable()->after('priority')
                ->constrained('task_priorities')->nullOnDelete();

            // Отдел проставили руками — автоподстановка по ответственному
            // больше не должна его перебивать.
            $table->boolean('department_locked')->default(false)->after('department_id');

            $table->index(['board_id', 'department_id', 'board_column_id']);
        });

        Schema::table('portal_users', function (Blueprint $table) {
            // Отделы сотрудника из оргструктуры портала (UF_DEPARTMENT).
            // Кэшируем, чтобы не дёргать user.get на каждую задачу при
            // синхронизации — лимит в 2 запроса в секунду этого не переживёт.
            $table->jsonb('bitrix_department_ids')->nullable()->after('position');
        });
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            $table->dropForeign(['department_id']);
            $table->dropForeign(['task_priority_id']);
            $table->dropIndex(['board_id', 'department_id', 'board_column_id']);
            $table->dropColumn(['department_id', 'task_priority_id', 'department_locked']);
        });

        Schema::table('portal_users', function (Blueprint $table) {
            $table->dropColumn('bitrix_department_ids');
        });
    }
};
