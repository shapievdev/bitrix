<?php

namespace App\Http\Controllers;

use App\Facades\Bitrix24;
use App\Models\Board;
use App\Models\PortalUser;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use App\Services\Bitrix24\Exceptions\TokenRefreshFailed;
use App\Services\Kanban\TaskSynchronizer;
use App\Support\PortalContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Быстрое создание задачи прямо с доски.
 */
class TaskCreationController extends Controller
{
    public function store(Request $request, Board $board, TaskSynchronizer $synchronizer): RedirectResponse
    {
        $validated = $request->validate([
            'title' => ['required', 'string', 'max:250'],
            'responsible_id' => ['nullable', 'integer', 'min:1'],
        ]);

        $user = PortalContext::user();

        [$title, $tags] = $this->splitTags($validated['title']);

        if ($title === '') {
            return back()->with('error', 'Название задачи не может состоять из одних тегов.');
        }

        $fields = [
            'TITLE' => $title,
            // По умолчанию задача на том, кто её создаёт: у быстрого
            // создания нет места, чтобы выбирать исполнителя, а задача без
            // исполнителя никому не видна.
            'RESPONSIBLE_ID' => $validated['responsible_id'] ?? $user?->bitrix_user_id,
            'CREATED_BY' => $user?->bitrix_user_id,
        ];

        if ($tags !== []) {
            $fields['TAGS'] = $tags;
        }

        try {
            $result = $this->addTask($board, $user, $fields);
        } catch (Bitrix24Exception $e) {
            return back()->with('error', 'Не удалось создать задачу: '.$e->getMessage());
        }

        $taskId = (int) ($result['task']['id'] ?? $result['task']['ID'] ?? 0);

        if ($taskId === 0) {
            return back()->with('error', 'Битрикс24 не вернул идентификатор созданной задачи.');
        }

        $synchronizer->addTaskToBoard($board, $taskId);

        return back()->with('success', "Задача #{$taskId} создана.");
    }

    /**
     * Создать задачу, по возможности от имени сотрудника.
     *
     * От имени пользователя — чтобы постановщиком не оказалось само
     * приложение. Но если его токен протух и обновить не удалось,
     * задачу всё равно надо создать: терять её из-за проблем с
     * авторизацией одного человека нельзя.
     *
     * @param  array<string, mixed>  $fields
     */
    protected function addTask(Board $board, ?PortalUser $user, array $fields): array
    {
        if ($user) {
            try {
                return Bitrix24::forUser($user)->call('tasks.task.add', ['fields' => $fields]);
            } catch (TokenRefreshFailed $e) {
                Log::info('Канбан: токен сотрудника недействителен, ставим задачу от приложения', [
                    'user' => $user->bitrix_user_id,
                ]);
            }
        }

        return Bitrix24::forPortal($board->portal)->call('tasks.task.add', ['fields' => $fields]);
    }

    /**
     * Отделить теги вида #срочно от названия.
     *
     * Битрикс так же разбирает строку в своём быстром создании, и
     * привычку пользователя ломать не стоит.
     *
     * @return array{0: string, 1: array<int, string>}
     */
    protected function splitTags(string $input): array
    {
        preg_match_all('/#([^\s#]+)/u', $input, $matches);

        $title = trim(preg_replace('/#[^\s#]+/u', '', $input));
        $title = trim(preg_replace('/\s+/u', ' ', $title));

        return [$title, array_values(array_unique($matches[1]))];
    }
}
