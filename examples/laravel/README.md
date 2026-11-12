# Новый отзыв Google Business Profile в Laravel

Пример принимает push-сообщение Cloud Pub/Sub, проверяет его подлинность, получает новый отзыв по API и записывает его оценку в журнал Laravel. Пример рассчитан на один аккаунт Business Profile.

## Маршрут и настройки Laravel

Скопируйте [контроллер](GoogleBusinessProfileWebhookController.php) в `app/Http/Controllers`. Добавьте маршрут в `routes/api.php` приложения с включённой маршрутизацией API. По умолчанию Laravel добавит префикс `/api`.

```php
use App\Http\Controllers\GoogleBusinessProfileWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/webhooks/google-business-profile', GoogleBusinessProfileWebhookController::class);
```

Добавьте настройки в массив `config/services.php`.

```php
'google_business_profile' => [
    'account' => env('GBP_ACCOUNT'),
    'push_audience' => env('GBP_PUSH_AUDIENCE'),
    'push_service_account' => env('GBP_PUSH_SERVICE_ACCOUNT'),
    'subscription' => env('GBP_PUBSUB_SUBSCRIPTION'),
],
```

```dotenv
GBP_ACCOUNT=accounts/123456789
GBP_PUSH_AUDIENCE=https://example.com/api/webhooks/google-business-profile
GBP_PUSH_SERVICE_ACCOUNT=gbp-push@example-project.iam.gserviceaccount.com
GBP_PUBSUB_SUBSCRIPTION=projects/example-project/subscriptions/gbp-reviews
```

Добавьте регистрацию `PushReceiver` в метод `register()` вашего `AppServiceProvider`.

```php
use GoogleBusinessProfile\PubSub\PushReceiver;

$this->app->singleton(PushReceiver::class, fn () => new PushReceiver(
    audience: config('services.google_business_profile.push_audience'),
    serviceAccountEmail: config('services.google_business_profile.push_service_account'),
    subscription: config('services.google_business_profile.subscription'),
));
```

Контроллер также получает `GoogleBusinessProfile\Client` из контейнера Laravel. Зарегистрируйте его через `Client::fromGoogleClient($google)`, где `$google` настроен на OAuth 2.0 и сохранённый токен владельца или менеджера аккаунта из `GBP_ACCOUNT`. Для фоновых запросов требуется `refresh_token`. [Пример настройки клиента](../../README.md#oauth-и-чтение-отзывов)

## Подключение Pub/Sub

1. Создайте топик и дайте `mybusiness-api-pubsub@system.gserviceaccount.com` роль Pub/Sub Publisher на этом топике.
2. Создайте push-подписку с включёнными аутентификацией и JSON-обёрткой. Укажите публичный HTTPS-адрес Laravel, отдельный сервисный аккаунт доставки и значение audience из настроек приложения. Для синхронной загрузки отзыва задайте срок подтверждения, покрывающий проверку токена и запрос к API.
3. Привяжите топик к аккаунту Business Profile через авторизованный клиент SDK.

```php
$setting = config('services.google_business_profile.account') . '/notificationSetting';
$sdk->notifications()->accountsUpdateNotificationSetting(
    $setting,
    [
        'name' => $setting,
        'pubsubTopic' => 'projects/example-project/topics/gbp-notifications',
        'notificationTypes' => ['NEW_REVIEW'],
    ],
    ['updateMask' => 'pubsubTopic,notificationTypes'],
);
```

Для аккаунта Google поддерживает одну настройку уведомлений. При изменении существующей конфигурации прочитайте её через `accountsGetNotificationSetting()` и включите в запрос все нужные типы событий.

Контроллер возвращает HTTP 204 после обработки. Ошибка чтения отзыва остаётся ошибкой HTTP, чтобы Pub/Sub повторил доставку. При подключении записи в БД или других действий учитывайте повторные доставки. Например, обновляйте одну запись по полному имени ресурса отзыва.

- [Уведомления Google Business Profile](https://developers.google.com/my-business/content/notification-setup)
- [Аутентификация push-подписок](https://cloud.google.com/pubsub/docs/authenticate-push-subscriptions)
- [Push-подписки и подтверждение доставки](https://cloud.google.com/pubsub/docs/push)
