<?php

namespace Tests\Feature;

use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * Корень — точка входа для портала, а не публичная страница.
     * Прямое обращение из браузера должно упираться в отказ.
     */
    public function test_корень_не_открывается_без_запроса_от_портала(): void
    {
        $this->get('/')->assertForbidden();
    }

    public function test_проверка_живости_отвечает(): void
    {
        $this->get('/up')->assertOk();
    }
}
