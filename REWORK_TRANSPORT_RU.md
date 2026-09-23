# Переработка транспорта и локального протокола 7.00.014-mdiag2

Рабочий адрес: https://diag.devwork.tech/xdiag/ . Проверка Интернета сохранена.
Исходник CI: https://testnet-faucet.devwork.tech/X-DIAG_V7.00.014.apk,
SHA256: 609b6fdaaf059a36b5442fce28e1341794912a4dd8378a468b1833c4f0c9c423.

## Что было обнаружено

Основной вход module/b1/a/a создаёт FormBody (j.q) и выполняет j.x.newCall.
Он НЕ использует Apache AsyncHttpClient. module/h/c создаёт OkHttp через
HttpUtil.a, который устанавливает SSLContext из встроенного BKS поставщика.
Прежнее изменение только Apache не исправляло этот путь.
До сетевого try/catch вызывается TelephonyManager.getDeviceId. SecurityException
на этом месте оставлял сервер без запроса. Теперь при недоступности IMEI
используется Android ID — аналог предусмотренного оригиналом fallback при null.
Конкретная причина сбоя на пользовательском планшете требует его журнала.

## Новая граница HTTP

NetworkBridge вызывается из реального OkHttpClient.newCall для execute/enqueue.
Маршрутизируются известные домены, IP 79.174.70.97/103 на путях API и старые
локальные домены из сохранённых настроек. Путь, query, POST и тело сохраняются.
Уже локальные URL не получают повторный /xdiag. Голый SOAP namespace не меняется.
Передача для локального хоста использует отдельную копию клиента с системным
X509TrustManager и строгим OkHostnameVerifier. Внешний клиент сохраняется.
Нельзя устанавливать самоподписанный сертификат вместо полноценной цепочки
Let's Encrypt. Никаких глобальных trust-all/отключений hostname здесь нет.

Оригинальное разрешение cleartext HTTP восстановлено. CI сравнивает тела методов
проверки Интернета до/после, допуская лишь замену диагностических URL.
Сторонние интернет-сервисы, native-сокеты, XMPP/удалённая диагностика этим HTTP
адаптером не реализуются. Локальный Laravel не пересылает данные планшета наружу.

## Сервер

PassportApi: login/register/logout, DTO профиля/мастерской, сохранение профиля,
смена пароля pw/chpw с отзывом сессий. BootstrapApi: локальная таблица URL.
LocalAuth: проверка локального пароля, token и подписей. LocalCatalog: список
сканеров/версий и файлы с ACL. Вход аккаунтом поставщика в APK не подразумевается:
его логин из .env используется исключительно командой официальной синхронизации.

Неизвестные команды по-прежнему возвращают явную ошибку и событие unsupported_command.
Это не полная копия закрытого backend: платежи, облачная удалённая диагностика,
социальные функции, все варианты web-SSO и сторонние сервисы пока не реализованы.
Не возвращаем фиктивное «успешно» для этих операций. Полная совместимость с каждым
экраном не подтверждается unit-тестами и требует функциональных проверок на устройстве.

## Обновление установленного Laravel

Из каталога Laravel (путь пакета скорректируйте, если использовали другой):

```bash
git -C packages/DevWorkTech/MDiagRepository pull --ff-only
composer dump-autoload
php artisan migrate --force
php artisan optimize:clear
php artisan mdiag:doctor
curl -fsS https://diag.devwork.tech/xdiag/health
```

Health должен содержать revision=transport2. Опубликованный config/mdiag-dwt.php
должен содержать домен diag.devwork.tech, https; APP_URL основного проекта не меняем.
Для входа создайте локального пользователя (пароль вводится скрыто):

```bash
php artisan mdiag:user xdiag mechanic --create --status=active --downloads=allow --all-modules
php artisan mdiag:scanner:assign xdiag mechanic ВАШ_СЕРИЙНЫЙ_НОМЕР
```

На Android имя версии — 7.00.014-mdiag2, пакет tech.devwork.mxdiag.
Файл релиза сохраняет имя mX-DIAG-7.00.014.apk. Если CI использует ephemeral key,
обновление поверх прежней сборки может быть отвергнуто из-за подписи. Не удаляйте
данные для диагностики вслепую: сначала проверьте фактически установленную версию.
Для последующих обновлений настройте постоянный signing key по android/NETWORK_RU.md.

## Где увидеть причину следующего сбоя

При APP_DEBUG=true сервер пишет storage/logs/mdiag-debug-YYYY-MM-DD.log.
Входящему запросу присваивается/сохраняется X-MDiag-Request-Id. Такой же id виден
в logcat APK: строки MDiagNetwork с этапами login_prepare, local_okhttp_ready,
request, response или network_failed. Пароли, token и тела в новом журнале не пишутся.
После начала входа APK также пытается записать небольшой ротируемый журнал в
Android/data/tech.devwork.mxdiag/files/mdiag-network.log.

```bash
adb shell dumpsys package tech.devwork.mxdiag | grep versionName
adb logcat -v time -s System.err:* AndroidRuntime:* > mdiag-device.log
```

Запустите запись, воспроизведите ошибку входа и остановите Ctrl+C. Перед отправкой
оставьте строки MDiagNetwork/MDiagTLS и относящийся к сбою stack trace: оригинальные
сторонние логгеры APK могут писать другие данные. Если есть request, но нет incoming
с тем же id — проверьте DNS/TLS/маршрут с планшета. HTTP response и X-MDiag-Error
покажут, дошёл ли вызов до Laravel; network_failed содержит класс сетевой ошибки.
