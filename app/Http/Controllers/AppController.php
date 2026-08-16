<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;

class AppController extends Controller
{
    /**
     * Точка входа приложения.
     *
     * Отдельной главной страницы нет: смысл приложения — доски, и лишний
     * экран со статистикой портала между входом и работой только мешает.
     */
    public function home(): RedirectResponse
    {
        return redirect()->route('app.boards.index');
    }
}
