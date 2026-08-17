<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/**
 * Имена маршрутов, на которые ссылается фронтенд, должны существовать.
 *
 * Ziggy на неизвестном имени бросает исключение прямо во время отрисовки,
 * и Vue не монтируется вовсе — приложение открывается пустым белым
 * экраном без единой ошибки в логах сервера. Один переименованный
 * маршрут кладёт весь интерфейс, поэтому проверяем связь статически.
 */
class FrontendRoutesTest extends TestCase
{
    public function test_все_маршруты_из_vue_компонентов_существуют(): void
    {
        $known = collect(Route::getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->all();

        $missing = [];

        foreach ($this->vueFiles() as $file) {
            $contents = file_get_contents($file);

            preg_match_all("/route\(\s*'([^']+)'/", $contents, $matches);

            foreach ($matches[1] as $name) {
                if (! in_array($name, $known, true)) {
                    $missing[] = basename($file).' → '.$name;
                }
            }
        }

        $this->assertSame([], $missing, 'Фронтенд ссылается на несуществующие маршруты');
    }

    /**
     * @return array<int, string>
     */
    protected function vueFiles(): array
    {
        $directory = new \RecursiveDirectoryIterator(resource_path('js'));
        $files = [];

        foreach (new \RecursiveIteratorIterator($directory) as $file) {
            if ($file->isFile() && $file->getExtension() === 'vue') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }
}
