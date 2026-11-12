# Новый отзыв Google Business Profile на чистом PHP

Оба примера обрабатывают событие `NEW_REVIEW`, загружают отзыв через SDK и выводят ID сообщения, имя ресурса отзыва и оценку. Примеры рассчитаны на один аккаунт Business Profile.

## Общая настройка

```sh
composer require kith-web-studio/google-business-profile-php
```

Задайте переменные среды для HTTP-процесса или CLI. Значения `GBP_OAUTH_CLIENT_SECRET` и `GBP_OAUTH_REFRESH_TOKEN` берутся из настроек и авторизации вашего приложения.

| Переменная | Значение |
|---|---|
| `GBP_ACCOUNT` | Имя аккаунта Business Profile, например `accounts/123456789` |
| `GBP_OAUTH_CLIENT_ID` | ID OAuth-клиента в одобренном проекте Google Cloud |
| `GBP_OAUTH_CLIENT_SECRET` | Секрет того же OAuth-клиента |
| `GBP_OAUTH_REFRESH_TOKEN` | Токен владельца или менеджера с доступом к аккаунту и областью `business.manage` |

Создайте топик Cloud Pub/Sub и дайте `mybusiness-api-pubsub@system.gserviceaccount.com` роль Pub/Sub Publisher на нём. Привяжите топик к аккаунту и выберите `NEW_REVIEW` через `accountsUpdateNotificationSetting()`. Для аккаунта действует одна настройка уведомлений. Если она уже используется, сохраните нужные типы событий при обновлении. [Уведомления Business Profile](https://developers.google.com/my-business/content/notification-setup) · [Пример вызова настройки из SDK](../laravel/README.md#подключение-push-подписки)

## Push через HTTPS

Создайте аутентифицированную push-подписку с JSON-обёрткой и направьте её на `https://example.com/push.php`. Опубликуйте [`public/push.php`](public/push.php) из этого каталога через PHP на HTTPS-сервере. Передавайте в PHP заголовок `Authorization`. Для проверки JWT требуется расширение OpenSSL.

| Переменная | Пример |
|---|---|
| `GBP_PUBSUB_PUSH_SUBSCRIPTION` | `projects/example-project/subscriptions/gbp-reviews-push` |
| `GBP_PUSH_AUDIENCE` | `https://example.com/push.php`, значение audience из подписки |
| `GBP_PUSH_SERVICE_ACCOUNT` | `gbp-push@example-project.iam.gserviceaccount.com`, аккаунт из настроек аутентификации подписки |

Для локальной проверки HTTP-маршрута можно запустить встроенный сервер PHP из корня приложения.

```sh
php -S 127.0.0.1:8080 -t vendor/kith-web-studio/google-business-profile-php/examples/php/public
```

Адрес для этой локальной проверки — `http://127.0.0.1:8080/push.php`. Для реальной доставки Google нужен публичный HTTPS-адрес. Обработчик записывает оценку в журнал PHP и возвращает `204`. Неверная авторизация получает `401`, повреждённые данные получают `400`, ошибка запроса к API получает `500`. [Аутентификация push-подписки](https://cloud.google.com/pubsub/docs/authenticate-push-subscriptions) · [Подтверждение доставки](https://cloud.google.com/pubsub/docs/push)

## Pull из CLI

Создайте pull-подписку. Задайте `GBP_PUBSUB_PULL_SUBSCRIPTION`, например `projects/example-project/subscriptions/gbp-reviews-pull`. Настройте Application Default Credentials для процесса PHP и выдайте его учётной записи роль `roles/pubsub.subscriber` на pull-подписке. Эти учётные данные используются для Pub/Sub. Для чтения самого отзыва используются переменные OAuth из общей настройки. [Аутентификация Pub/Sub](https://cloud.google.com/pubsub/docs/authentication) · [Права на pull-подписку](https://cloud.google.com/pubsub/docs/pull-messages#required_roles_and_permissions)

Запустите [`pull.php`](pull.php) из корня приложения.

```sh
php vendor/kith-web-studio/google-business-profile-php/examples/php/pull.php
```

Скрипт забирает до пяти сообщений за запуск и печатает сведения о новых отзывах в stdout как JSON, по одному объекту на строку. Он подтверждает сообщения после обработки. При ошибке он пишет в stderr, продолжает обработку пачки и завершает работу с кодом `1`. Необработанное сообщение остаётся доступным для повторной доставки после истечения срока подтверждения. При записи в БД используйте имя ресурса отзыва как ключ, чтобы повторная доставка обновляла ту же запись. [Получение сообщений через pull](https://cloud.google.com/pubsub/docs/pull-messages)
