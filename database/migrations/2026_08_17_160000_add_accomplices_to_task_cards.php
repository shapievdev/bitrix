<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            // Соисполнители задачи. Раньше они использовались только для
            // вычисления отделов и выбрасывались, но на карточке нужно
            // показывать самих людей — а не подразделения, к которым они
            // относятся.
            $table->jsonb('accomplice_ids')->nullable()->after('responsible_id');
        });
    }

    public function down(): void
    {
        Schema::table('task_cards', fn (Blueprint $table) => $table->dropColumn('accomplice_ids'));
    }
};
