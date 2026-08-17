<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            // Оргструктура портала — дерево. Держим и своё родство, и
            // родство в Битриксе: первое нужно для навигации, второе —
            // чтобы повторный импорт находил уже созданные узлы.
            $table->foreignId('parent_id')->nullable()->after('portal_id')
                ->constrained('departments')->nullOnDelete();
            $table->unsignedBigInteger('bitrix_parent_id')->nullable()->after('bitrix_department_id');

            // Основные департаменты — верхний уровень навигации. В дереве
            // портала они лежат на разной глубине (часть под «Исполнительным
            // директором», часть под корнем), поэтому вычислить их по
            // родителю нельзя — отмечаем явно.
            $table->boolean('is_primary')->default(false)->after('is_default');

            $table->index(['portal_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('departments', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['portal_id', 'parent_id']);
            $table->dropColumn(['parent_id', 'bitrix_parent_id', 'is_primary']);
        });
    }
};
