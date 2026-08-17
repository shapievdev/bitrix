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
            // Название в нижнем регистре для поиска.
            //
            // ILIKE и lower() в Postgres складывают регистр по локали базы,
            // а в локали C кириллица не складывается вовсе: «ПРАЙС» и
            // «прайс» остаются разными строками. Локаль базы — не то, на
            // что должен опираться поиск, поэтому приводим регистр в PHP
            // и храним готовое значение.
            $table->text('title_normalized')->nullable()->after('title');
            $table->index('title_normalized');
        });

        DB::statement('update task_cards set title_normalized = lower(title)');
    }

    public function down(): void
    {
        Schema::table('task_cards', function (Blueprint $table) {
            $table->dropIndex(['title_normalized']);
            $table->dropColumn('title_normalized');
        });
    }
};
