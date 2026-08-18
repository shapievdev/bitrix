<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            // Наблюдатели задачи. Нужны для видимости: наблюдатель за
            // задачей не следит вслепую и должен её видеть на доске.
            $table->jsonb('auditor_ids')->nullable()->after('accomplice_ids');

            // Когда карточка попала в текущую колонку. Раньше это
            // вычислялось из истории переходов, но на доске в сотни
            // карточек подгружать историю каждой — лишние запросы ради
            // одной даты.
            $table->timestamp('entered_column_at')->nullable()->after('closed_at');
        });

        Schema::table('departments', function (Blueprint $table) {
            // Руководитель подразделения из UF_HEAD. Хранится
            // идентификатором Битрикса, а не ссылкой на portal_users:
            // руководитель может ещё ни разу не открывать приложение.
            $table->unsignedBigInteger('head_id')->nullable()->after('bitrix_parent_id');
            $table->index('head_id');
        });

        $this->backfillEnteredColumnAt();
    }

    /**
     * Проставить точку входа в колонку уже существующим карточкам.
     *
     * Без этого у всех задач счётчик времени на этапе начался бы с нуля в
     * момент выкладки, и доска первые дни врала бы.
     */
    protected function backfillEnteredColumnAt(): void
    {
        // Последний переход карточки — момент, когда она оказалась в
        // нынешней колонке. Если переходов не было, карточка лежит там с
        // самого появления на доске.
        //
        // Одним коррелированным подзапросом, а не update с join: в
        // Postgres второй разворачивается в форму с ctid, где таблица
        // соединения снаружи не видна.
        DB::table('task_cards')->update([
            'entered_column_at' => DB::raw(
                'coalesce('
                .'(select max(created_at) from card_transitions '
                .'where card_transitions.task_card_id = task_cards.id), '
                .'task_cards.created_at)'
            ),
        ]);
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            $table->dropColumn(['auditor_ids', 'entered_column_at']);
        });

        Schema::table('departments', function (Blueprint $table) {
            $table->dropIndex(['head_id']);
            $table->dropColumn('head_id');
        });
    }
};