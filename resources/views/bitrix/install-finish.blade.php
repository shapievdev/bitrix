<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <title>Установка приложения</title>
    <script src="//api.bitrix24.com/api/v1/"></script>
</head>
<body style="font: 15px/1.5 -apple-system, 'Segoe UI', Roboto, sans-serif; padding: 24px; color: #202124;">
    <p>Приложение установлено.</p>

    <script>
        // Без installFinish портал не пометит установку завершённой и будет
        // показывать приложение как «устанавливается» до перезагрузки.
        BX24.init(function () {
            BX24.installFinish();
        });
    </script>
</body>
</html>