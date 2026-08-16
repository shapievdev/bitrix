<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('departments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('portal_id')->constrained()->cascadeOnDelete();

            $table->string('name');
            $table->string('color', 16)->default('#64748b');
            $table->unsignedInteger('position')->default(0);

            // Связь с отделом оргструктуры портала. Необязательная: список
            // подразделений свой, но при заполненной связи задача сама
            // попадает в нужную дорожку по отделу ответственного.
            $table->unsignedBigInteger('bitrix_department_id')->nullable();

            // Куда падают задачи, для которых отдел определить не удалось.
            $table->boolean('is_default')->default(false);

            $table->timestamps();

            $table->index(['portal_id', 'position']);
            $table->unique(['portal_id', 'bitrix_department_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('departments');
    }
};
