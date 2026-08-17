<?php

namespace App\Http\Controllers\Bitrix24;

use App\Http\Controllers\Controller;
use App\Services\Bitrix24\Exceptions\Bitrix24Exception;
use App\Services\Bitrix24\PortalRegistrar;
use App\Services\Bitrix24\TokenSet;
use App\Support\PortalContext;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Вход в приложение из интерфейса Битрикс24.
 *
 * Битрикс открывает iframe обычным POST-запросом с токенами текущего
 * сотрудника. Здесь мы их проверяем, заводим портал и пользователя,
 * кладём в сессию и переводим на страницу приложения.
 *
 * Редирект на GET после POST нужен не только ради чистоты: без него
 * обновление страницы во фрейме повторяет POST с уже истёкшим AUTH_ID.
 */
class EntryController extends Controller
{
    public function __construct(protected PortalRegistrar $registrar) {}

    public function __invoke(Request $request)
    {
        $params = $request->all();

        if (empty($params['AUTH_ID']) || empty($params['member_id'])) {
            return response()->view('bitrix.install-failed', [
                'message' => 'Приложение открыто вне Битрикс24 либо портал не передал данные авторизации.',
            ], 403);
        }

        $tokens = TokenSet::fromPlacementRequest($params);

        try {
            $portal = $this->registrar->upsertPortal($tokens);
            $user = $this->registrar->upsertCurrentUser($portal, $tokens);
        } catch (Bitrix24Exception $e) {
            Log::warning('Bitrix24: не удалось подтвердить вход', [
                'member_id' => $params['member_id'] ?? null,
                'error' => $e->getMessage(),
            ]);

            return response()->view('bitrix.install-failed', [
                'message' => 'Не удалось подтвердить авторизацию на портале. Переустановите приложение.',
            ], 403);
        }

        // Смена идентификатора сессии на входе — защита от фиксации сессии.
        $request->session()->regenerate();
        $request->session()->put('bitrix.portal_id', $portal->id);
        $request->session()->put('bitrix.user_id', $user->id);

        PortalContext::set($portal, $user);

        // Место встраивания и его параметры определяют, какой экран открыть:
        // из карточки задачи приходит её ID, из режима просмотра — ничего.
        $placement = $params['PLACEMENT'] ?? 'DEFAULT';
        $options = json_decode($params['PLACEMENT_OPTIONS'] ?? '[]', true) ?: [];

        $request->session()->put('bitrix.placement', $placement);
        $request->session()->put('bitrix.placement_options', $options);

        return redirect()->to($this->destination($placement, $options));
    }

    /**
     * Куда вести после рукопожатия.
     *
     * Приложение открывается из разных мест портала, и в каждом от него
     * ждут разного: из карточки задачи — эту задачу, из раздела задач —
     * доску целиком.
     */
    protected function destination(string $placement, array $options): string
    {
        $taskId = (int) ($options['taskId'] ?? $options['TASK_ID'] ?? $options['ID'] ?? 0);

        if ($placement === 'TASK_VIEW_TAB' && $taskId > 0) {
            return route('app.tasks.show', $taskId);
        }

        return route('app.home');
    }
}
