<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portals', function (Blueprint $table) {
            $table->id();

            // member_id — единственный стабильный идентификатор портала.
            // Домен может смениться (переезд на свой домен, ребрендинг), а
            // member_id остаётся, поэтому ключ именно на нём.
            $table->string('member_id')->unique();
            $table->string('domain')->index();
            $table->enum('kind', ['cloud', 'onpremise'])->default('cloud');

            // Токены уровня портала — получены при установке (от админа).
            // Используются для фоновых задач, когда пользователя в контексте нет.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            // Приходит с событием ONAPPINSTALL, нужен для проверки подлинности
            // входящих событий. Без него обработчик событий открыт всему миру.
            // text, а не string: в зашифрованном виде токен длиннее 255 байт.
            $table->text('application_token')->nullable();

            // jsonb, а не json: по правам придётся фильтровать порталы,
            // а индексировать и искать умеет только jsonb.
            $table->jsonb('scope')->nullable();
            $table->string('app_version')->nullable();
            $table->string('lang', 8)->default('ru');

            $table->boolean('is_active')->default(true);
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('uninstalled_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portals');
    }
};
