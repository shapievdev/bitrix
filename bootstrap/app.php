<?php

use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\ResolveBitrixPortal;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            HandleInertiaRequests::class,
        ]);

        // Контекст портала должен подниматься раньше подстановки моделей:
        // Route::get('boards/{board}') ищет доску глобальным скоупом, а тот
        // без контекста ничего не найдёт. Без этой строки собственные доски
        // отдавали бы 404, а с прежним послаблением скоупа — открывались бы
        // чужие.
        $middleware->prependToPriorityList(
            before: SubstituteBindings::class,
            prepend: ResolveBitrixPortal::class,
        );

        // Запросы от портала приходят server-to-server, CSRF-токена в них
        // быть не может. Подлинность этих трёх адресов проверяется иначе:
        // установка и вход — по рабочему AUTH_ID, события — по
        // application_token.
        $middleware->validateCsrfTokens(except: [
            '/',
            'bitrix/install',
            'bitrix/event',
            'bitrix/app/*',
        ]);

        // Приложение всегда за HTTPS-прокси (ngrok при разработке, балансировщик
        // в проде). Без доверия заголовкам Laravel сгенерирует http-ссылки, и
        // Битрикс откажется грузить их во фрейме.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
