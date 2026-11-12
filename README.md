# Google Business Profile SDK для PHP

Composer-пакет для Google Business Profile REST API. Требуется PHP 8.1 или новее. Пакет можно подключить к Laravel или к приложению без фреймворка.

Клиент покрывает семь API v1, методы My Business v4.9 для отзывов, публикаций, меню и медиа, загрузку файлов через Media v1 и канал Business Information v1alpha. В v4.9 также доступны задокументированные методы пакетного чтения карточек и работы со списком услуг. Для обычного управления карточками и услугами используйте Business Information v1.

- [Методы SDK и соответствующие REST-запросы](API.md)
- [Справочник Google Business Profile](https://developers.google.com/my-business/ref_overview)
- [График отключения API](https://developers.google.com/my-business/content/sunset-dates)

## Установка

Выполните в корне вашего проекта.

```sh
composer require kith-web-studio/google-business-profile-php
```

## OAuth и чтение отзывов

Для реальных запросов включите нужные API в одобренном проекте Google Cloud. OAuth-токен должен принадлежать пользователю с правами на нужные карточки. В приложении храните токены отдельно для каждого подключённого аккаунта.

```php
use Google\Client as GoogleClient;
use GoogleBusinessProfile\Client;

// $token — результат обмена кода OAuth, сохранённый приложением.
$google = new GoogleClient();
$google->setClientId($clientId);
$google->setClientSecret($clientSecret);
$google->addScope(Client::SCOPE);
$google->setAccessToken($token);

// Для фоновых запросов сохраните исходный refresh_token при подключении.
$sdk = Client::fromGoogleClient($google);

$accounts = $sdk->accountManagement()->accountsList();

foreach ($accounts['accounts'] ?? [] as $account) {
    $locations = $sdk->businessInformation()->accountsLocationsList($account['name'], [
        'readMask' => 'name,title',
        'pageSize' => 10,
    ]);

    foreach ($locations['locations'] ?? [] as $location) {
        $reviews = $sdk->myBusiness()->reviewsList(
            $account['name'] . '/' . $location['name'],
            ['pageSize' => 10],
        );
        // Используйте $reviews['reviews'] и $reviews['nextPageToken'].
    }
}
```

SDK добавляет OAuth-токен к запросам и использует Google PHP Client для обновления токена при наличии `refresh_token`. Реализация OAuth callback и сохранение токенов принадлежат приложению. После обновления текущий токен можно получить через `$google->getAccessToken()`. При первичной авторизации для фоновой работы используйте `setAccessType('offline')`. Сохраните прежний `refresh_token`, если при повторной авторизации Google вернул только access token. [OAuth для Business Profile](https://developers.google.com/my-business/content/implement-oauth)

## Параметры и ошибки

- Передавайте полные имена ресурсов Google, например `locations/456` для v1 и `accounts/123/locations/456` для отзывов v4.
- Передавайте поля JSON как массив или объект. Ответы возвращаются как массивы. При пустом теле возвращается `[]`.
- Для пагинации передавайте `nextPageToken` из ответа в `pageToken` следующего запроса.
- Повторяющиеся параметры задавайте списком. Вложенные параметры запроса задавайте ключами с точкой, например `dailyRange.startDate.year`.
- HTTP-ошибки Guzzle содержат исходный статус и тело ответа Google. Повторы запросов, включая обработку квот и операций записи, задаёт приложение.
- Канал `businessInformationAlpha()` работает с реальными карточками. Google описывает его как предварительную версию. [Версии API](https://developers.google.com/my-business/content/api-versions)

Для загрузки файла получите `resourceName` через `myBusiness()->mediaStartUpload($location)`, передайте открытый поток или байты в `mediaUpload()->upload($resourceName, $data)`, затем вызовите `myBusiness()->mediaCreate($location, $body)` с этим значением в `dataRef.resourceName`. [Загрузка медиа](https://developers.google.com/my-business/content/upload-photos)

## Локальная проверка

Из каталога SDK выполните `composer install` и `composer test`. Проверка использует подставные HTTP-ответы и не требует доступа к Google Business Profile. Для тестов приложения можно передать собственный клиент Guzzle в `new Client($http)`.

Для обновления методов по опубликованным схемам выполните `python tools/generate.py` (Python 3.10+), просмотрите изменения в API.md и запустите проверку. Методы v4.9 сверяются с архивной схемой и актуальной документацией Google.
