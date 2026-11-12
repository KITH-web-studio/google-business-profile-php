# Google Business Profile PHP SDK

## Интеграция в приложение

- Определи установленную версию и путь через `composer show kith-web-studio/google-business-profile-php --format=json`. Сверяй имена методов и параметры с [API.md](API.md) и классами в `src/Api/` установленной версии. Пакет работает на PHP 8.1+.
- Для Business Profile API используй `Client::fromGoogleClient($google)` и OAuth-токен пользователя с правами на карточку. Для pull-подписки используй `PullReceiver::fromApplicationDefaultCredentials()` с правами на подписку. Получай секреты из конфигурации приложения и исключай их из кода и журналов.
- Для чтения отзывов получи аккаунты через `accountManagement()->accountsList()` и филиалы через `businessInformation()->accountsLocationsList()`. В v1 имя филиала выглядит как `locations/456`. Для `myBusiness()->reviewsList()` используй имя v4, например `accounts/123/locations/456`. Передавай `nextPageToken` из ответа в `pageToken` следующего запроса.
- Для `NEW_REVIEW` получи имя отзыва через `$notification->reviewName($accountName)`, затем вызови `myBusiness()->reviewsGet()`. После успешной обработки верни HTTP 204 для push или вызови `acknowledge()` для pull. При записи в БД учитывай повторную доставку и используй имя ресурса отзыва как ключ.
- Возьми рабочий пример для [чистого PHP](examples/php/README.md) или [Laravel](examples/laravel/README.md). Для локальной проверки передай в `new Client($http)` Guzzle-клиент с `MockHandler` по образцу [тестов SDK](tests/smoke.php). Укажи, какие запросы проверены на подставных ответах и какие прошли с реальной карточкой.

## Изменения в SDK

- Сохраняй исходники в UTF-8 без BOM.
- Для обновления сгенерированных методов и [API.md](API.md) используй [генератор](tools/generate.py) и проверь полученный diff.
- Для изменений кода запусти `composer test` из корня SDK. Тесты используют подставные ответы и не требуют доступа к Google Business Profile.
