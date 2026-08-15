<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('portal_users', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();

            // ID пользователя на стороне Битрикс24.
            $table->unsignedBigInteger('bitrix_user_id');

            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('avatar')->nullable();
            $table->string('position')->nullable();
            $table->string('timezone')->nullable();

            $table->boolean('is_admin')->default(false);

            // Пользовательские токены: нужны, когда действие должно выполняться
            // от имени пользователя (иначе задача создастся от имени приложения).
            // Живут недолго — обновляются при каждом входе в iframe.
            $table->text('access_token')->nullable();
            $table->text('refresh_token')->nullable();
            $table->timestamp('token_expires_at')->nullable();

            $table->timestamp('last_login_at')->nullable();
            $table->timestamps();

            $table->unique(['portal_id', 'bitrix_user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('portal_users');
    }
};
