<?php

namespace App\Enums;

/**
 * Штатные статусы задачи Битрикс24 (поле STATUS).
 *
 * Приложение добавляет свои колонки поверх, но привязка к этим значениям
 * нужна: закрытие задачи в Битриксе должно двигать карточку, и наоборот.
 */
enum TaskStatus: int
{
    case New = 1;
    case Pending = 2;
    case InProgress = 3;
    case Supposedly = 4;
    case Completed = 5;
    case Deferred = 6;
    case Declined = 7;

    public function label(): string
    {
        return match ($this) {
            self::New => 'Новая',
            self::Pending => 'Ждёт выполнения',
            self::InProgress => 'Выполняется',
            self::Supposedly => 'Ждёт контроля',
            self::Completed => 'Завершена',
            self::Deferred => 'Отложена',
            self::Declined => 'Отклонена',
        };
    }

    /**
     * Задача больше не в работе.
     */
    public function isClosed(): bool
    {
        return in_array($this, [self::Completed, self::Declined], true);
    }

    /**
     * Набор колонок по умолчанию у новой доски.
     *
     * «Готово» и «Завершены» — разные вещи, и смешивать их нельзя.
     * «Готово» это наш собственный этап: исполнитель закончил работу, но
     * задача в портале ещё открыта, и перенос в неё ничего там не меняет.
     * «Завершены» отражает реальное закрытие задачи в Битрикс24.
     *
     * @return array<int, array{name: string, status: ?self, color: string, final: bool}>
     */
    public static function defaultColumns(): array
    {
        return [
            ['name' => 'Новые', 'status' => self::New, 'color' => '#748a9d', 'final' => false],
            ['name' => 'В работе', 'status' => self::InProgress, 'color' => '#2fc7f7', 'final' => false],
            ['name' => 'На проверке', 'status' => self::Supposedly, 'color' => '#f6a800', 'final' => false],
            ['name' => 'Готово', 'status' => null, 'color' => '#8f79d9', 'final' => false],
            ['name' => 'Завершены', 'status' => self::Completed, 'color' => '#4ec24e', 'final' => true],
        ];
    }
}
