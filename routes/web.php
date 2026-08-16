<?php

use App\Http\Controllers\AppController;
use App\Http\Controllers\Bitrix24\EntryController;
use App\Http\Controllers\Bitrix24\EventController;
use App\Http\Controllers\Bitrix24\InstallController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\CardController;
use App\Http\Controllers\DictionaryController;
use App\Http\Middleware\BitrixFrameHeaders;
use App\Http\Middleware\ResolveBitrixPortal;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Точки входа со стороны Битрикс24
|--------------------------------------------------------------------------
|
| Все три адреса вызываются самим порталом, а не браузером пользователя,
| поэтому CSRF-токена в них нет — исключения прописаны в bootstrap/app.php.
| Методы разрешены и GET, и POST: Битрикс использует оба в зависимости
| от типа приложения и места встраивания.
|
*/

Route::match(['get', 'post'], '/bitrix/install', InstallController::class)
    ->middleware(BitrixFrameHeaders::class)
    ->name('bitrix.install');

Route::post('/bitrix/event', EventController::class)
    ->name('bitrix.event');

Route::match(['get', 'post'], '/', EntryController::class)
    ->middleware(BitrixFrameHeaders::class)
    ->name('bitrix.entry');

Route::match(['get', 'post'], '/bitrix/app/{placement}', EntryController::class)
    ->middleware(BitrixFrameHeaders::class)
    ->name('bitrix.entry.placement');

/*
|--------------------------------------------------------------------------
| Само приложение
|--------------------------------------------------------------------------
|
| Открывается только после рукопожатия: ResolveBitrixPortal поднимает
| портал и пользователя, иначе запрос дальше не проходит.
|
*/

Route::middleware([ResolveBitrixPortal::class, BitrixFrameHeaders::class])
    ->prefix('app')
    ->name('app.')
    ->group(function () {
        Route::get('/', [AppController::class, 'home'])->name('home');

        Route::get('boards', [BoardController::class, 'index'])->name('boards.index');
        Route::post('boards', [BoardController::class, 'store'])->name('boards.store');
        Route::get('boards/{board}', [BoardController::class, 'show'])->name('boards.show');
        Route::delete('boards/{board}', [BoardController::class, 'destroy'])->name('boards.destroy');
        Route::post('boards/{board}/sync', [BoardController::class, 'sync'])->name('boards.sync');

        Route::patch('cards/{card}/move', [CardController::class, 'move'])->name('cards.move');
        Route::patch('cards/{card}/priority', [CardController::class, 'priority'])->name('cards.priority');

        // Справочники: подразделения и приоритеты — портальные,
        // колонки — свои у каждой доски.
        Route::get('boards/{board}/settings', [DictionaryController::class, 'index'])->name('boards.settings');

        Route::post('departments', [DictionaryController::class, 'storeDepartment'])->name('departments.store');
        Route::patch('departments/{department}', [DictionaryController::class, 'updateDepartment'])->name('departments.update');
        Route::delete('departments/{department}', [DictionaryController::class, 'destroyDepartment'])->name('departments.destroy');
        Route::post('departments/import', [DictionaryController::class, 'importDepartments'])->name('departments.import');

        Route::post('priorities', [DictionaryController::class, 'storePriority'])->name('priorities.store');
        Route::patch('priorities/{priority}', [DictionaryController::class, 'updatePriority'])->name('priorities.update');
        Route::delete('priorities/{priority}', [DictionaryController::class, 'destroyPriority'])->name('priorities.destroy');

        Route::post('boards/{board}/columns', [DictionaryController::class, 'storeColumn'])->name('columns.store');
        Route::patch('columns/{column}', [DictionaryController::class, 'updateColumn'])->name('columns.update');
        Route::delete('columns/{column}', [DictionaryController::class, 'destroyColumn'])->name('columns.destroy');
    });
