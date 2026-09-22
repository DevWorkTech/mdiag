# Установка MDiag DWT с нуля

## 1. Компоненты и изоляция

Laravel 12, PHP 8.2+ (пример PHP 8.4), Composer, Nginx. Расширения SOAP, cURL, XML/DOM,
mbstring, SQLite либо MySQL. Все таблицы модуля имеют префикс `mdiag_`, namespace `DevWorkTech\MDiag`.
Репозиторий проекта: https://github.com/DevWorkTech/mdiag; внутреннее имя пакета — `devworktech/mdiag-dwt`.
Инструкция рассчитана на новую БД. Старые таблицы автоматически не переименовываются и не удаляются.

```bash
sudo apt update
sudo apt install -y git unzip composer php8.4-cli php8.4-fpm php8.4-soap \
  php8.4-curl php8.4-xml php8.4-mbstring php8.4-zip php8.4-sqlite3
sudo install -d -o coder -g www-data -m 2755 /var/www/my-project
composer create-project laravel/laravel /var/www/my-project '^12.0'
cd /var/www/my-project
mkdir -p packages/DevWorkTech
git clone https://github.com/DevWorkTech/mdiag.git packages/DevWorkTech/MDiagRepository
composer config repositories.mdiag-dwt path packages/DevWorkTech/MDiagRepository/server
composer require devworktech/mdiag-dwt:@dev
php artisan vendor:publish --provider='DevWorkTech\MDiag\MDiagServiceProvider' --tag=mdiag-config
```

В существующем Laravel создание проекта пропускается; остальные шаги подключения те же.
Не копируйте классы в `app/` и не заменяйте `routes/api.php` основного проекта.

## 2. Настройки

`APP_URL` оставьте адресом основного Laravel-проекта. MDiag использует собственный
`$domain` в `config/mdiag-dwt.php` для ограничения маршрутов и генерации ссылок.
В существующем проекте также сохраните текущие DB/CACHE/SESSION настройки.
Ниже пример для новой установки; в существующей добавьте только MDIAG-параметры:

```dotenv
APP_ENV=production
APP_DEBUG=false
DB_CONNECTION=sqlite
DB_DATABASE=/var/www/my-project/database/database.sqlite
SESSION_DRIVER=file
CACHE_STORE=file
QUEUE_CONNECTION=sync
MDIAG_XDIAG_LOGIN='оплаченный_логин_владельца'
MDIAG_XDIAG_PASSWORD='пароль_поставщика'
```

APP_KEY оставьте сгенерированный Laravel; при пустом значении выполните `php artisan key:generate`.
Учётные данные поставщика не используются локальными пользователями. Для SQLite:

```bash
touch database/database.sqlite
php artisan config:clear
php artisan migrate --force
sudo chown -R coder:www-data storage bootstrap/cache database
sudo find storage bootstrap/cache database -type d -exec chmod 2770 {} +
sudo find storage bootstrap/cache database -type f -exec chmod 660 {} +
sudo chown coder:www-data .env
sudo chmod 640 .env
```

В `config/mdiag-dwt.php` задайте `$domain`. IP нигде в Laravel/APK не нужен: A-запись меняется на роутере.
Для официальной синхронизации TLS-проверка по вашей настройке выключена (`verify_tls=false`).
Это настройка внешнего консольного клиента, а не локального сертификата Nginx.

Резервная авторизация в `profiles.xdiag.sync.fallback_login`:

```php
'fallback_login' => [
    'enabled' => true,
    'serial_no' => 'СЕРИЙНЫЙ_НОМЕР_СКАНЕРА_ОПЛАЧЕННОГО_АККАУНТА',
    'timezone' => 'Europe/Moscow', // Укажите часовой пояс исходного планшета.
],
```

Резерв используется только после ошибки основного входа. Без SN пропускается с объяснением.
Локальные сканеры не подставляются в синхронизацию автоматически. SOAP не загружает WSDL.

## 3. DNS, сертификат, Nginx

На роутере сопоставьте `diag.devwork.local` текущему IP сервера. На клиенте проверьте `nslookup`.
Из каталога Laravel:

```bash
sudo bash packages/DevWorkTech/MDiagRepository/deploy/create-local-tls.sh diag.devwork.local
sudo cp packages/DevWorkTech/MDiagRepository/deploy/mdiag-log-format.conf /etc/nginx/conf.d/mdiag-log-format.conf
sudo cp packages/DevWorkTech/MDiagRepository/deploy/nginx-mdiag.conf /etc/nginx/sites-available/mdiag.conf
sudo ln -s /etc/nginx/sites-available/mdiag.conf /etc/nginx/sites-enabled/mdiag.conf
sudo nginx -t && sudo systemctl reload nginx
sudo systemctl enable --now php8.4-fpm
```

Перед включением исправьте `root`, сокет PHP и клиентскую LAN-подсеть при необходимости.
Удалите дублирующий server block того же домена, если он уже включён; другие сайты не изменяйте.
Если сертификат уже существует, используйте его реальные пути — скрипт не перезаписывает ключи.
Скопируйте только `/etc/nginx/mdiag-tls/mdiag-local-ca.crt` на планшет и установите CA.
Никогда не копируйте приватные ключи. Для установленного локального CA сборка APK должна ему доверять.

Смена IP требует только изменения DNS. Смена домена требует изменения конфига/Nginx/сертификата и сборки APK.

## 4. Локальные пользователи, подписки, сканеры

Создайте локальную учётную запись. Пароль вводится скрыто, не параметром командной строки:

```bash
php artisan mdiag:user xdiag mechanic --create --status=active --downloads=allow \
  --expires='2027-09-17 23:59:59' --module=BENZ --module=BMW
php artisan mdiag:scanner:assign xdiag mechanic 968590000001
```

Разрешить все локальные модули пользователю:

```bash
php artisan mdiag:user xdiag mechanic --all-modules
```

Разрешить только Mercedes конкретному сканеру:

```bash
php artisan mdiag:scanner:access xdiag 968590000001 allow --module=BENZ
```

Отозвать скачивание у сканера:

```bash
php artisan mdiag:scanner:access xdiag 968590000001 deny
```

Сообщения и сроки:

```bash
php artisan mdiag:user xdiag mechanic --message='Обратитесь к администратору' \
  --expired-message='Доступ завершён. Свяжитесь со мной для продления.' \
  --module-message='Этот модуль не входит в ваш пакет.' \
  --notification='Добавлены новые версии Mercedes'
php artisan mdiag:user xdiag mechanic --status=blocked
php artisan mdiag:user xdiag mechanic --status=active --expires=none
php artisan mdiag:user xdiag mechanic --password
php artisan mdiag:scanner:assign xdiag mechanic 968590000001 \
  --expires='2027-01-01' --message='Скачивание для этого сканера ограничено'
```

`--expires=none` убирает срок. `--no-modules` задаёт пустой список, `--all-modules` — весь каталог.
Пользователь и сканер должны одновременно разрешать скачивание. Срок и блокировки проверяются
при каждом запросе, включая запросы по ранее полученным ссылкам. Для передачи SN другому пользователю
нужен явный `--transfer`. Пользователь не может сам присвоить себе SN через API.

В БД доступны поля `mdiag_users.denied_message`, `expired_message`, `module_denied_message`,
`notification_text`. В `mdiag_scanner_access_rules` доступны `user_id`, `downloads_allowed`,
`allowed_modules`, `expires_at`, `denied_message`. JSON `allowed_modules=null` означает все, `[]` — ничего.

## 5. Общие тексты ошибок в БД

Пример через `php artisan tinker`:

```php
DB::table('mdiag_error_messages')->updateOrInsert(
    ['provider' => 'xdiag', 'key' => 'module_denied'],
    ['code' => 900004, 'http_status' => 200,
     'message' => 'Этот модуль пока не подключён. Обратитесь к администратору.',
     'created_at' => now(), 'updated_at' => now()]
);
```

Полный перечень ключей — `Services/Local/ErrorCatalog.php`. Подтверждённые коды входа из APK
сохранены, локальные ограничения имеют собственные коды. Ответы JSON/XML экранируются.
Произвольный текст передаётся в `msg/message`; некоторые экраны APK показывают вместо него
свою встроенную строку. Гарантированный показ всех уведомлений требует адаптации интерфейса APK.

## 6. Синхронизация и импорт

```bash
php artisan config:cache
php artisan mdiag:sync xdiag --list
php artisan mdiag:sync xdiag --list --updates
php artisan mdiag:sync xdiag
php artisan mdiag:sync xdiag --all --latest
php artisan mdiag:sync xdiag --module=BENZ --package-version=50.95
```

Все внешние запросы идут только от CLI с учётной записью владельца сервера.
Пакеты сохраняются по профилю, типу, SN источника, модулю и версии в `storage/app/private/mdiag/packages`.
Это позволяет нескольким SN иметь разные персональные архивы одной версии без перезаписи.

Ручной импорт:

```bash
php artisan mdiag:package:add xdiag BENZ 50.95 /srv/import/BENZ.zip --serial=968590000001
```

Без `--serial` импортируйте только действительно общий файл, подходящий всем разрешённым сканерам.
Сервер не изменяет подписи/лицензии внутри архивов и не превращает персональные файлы в общие.

## 7. Проверка и отсутствие внешнего трафика

```bash
php artisan route:list --name=mdiag-dwt
php artisan list mdiag
curl -k -I https://diag.devwork.local/up
```

На планшете используйте локальный логин/пароль. Сначала проверьте вход, список сканеров,
каталог и загрузку одного модуля, затем блокировку/истечение срока. Полная совместимость всех
экранов APK не заявляется: неподдержанные методы завершаются локальной ошибкой без проксирования.

На роутере запретите планшету WAN по IPv4 и IPv6, оставив локальный DNS и HTTPS этого сервера.
Запрет должен действовать до разрешающих правил/FastTrack. Также отключите мобильные данные/VPN
на планшете. Это необходимо: сторонние SDK внутри APK могут обращаться в Интернет самостоятельно.

Для строгой изоляции серверного HTTP-процесса используйте отдельный PHP-FPM pool/службу с запретом
исходящих подключений (и разрешением локальной БД, если она по TCP). Не применяйте такой запрет
ко всем вашим Laravel-модулям: внешняя синхронизация запускается отдельным CLI-процессом.
Отключите внешние error-tracking/analytics handlers для этого домена в основном Laravel-проекте.

## 8. Обновление и диагностика

```bash
cd /var/www/my-project/packages/DevWorkTech/MDiagRepository
git pull --ff-only
cd /var/www/my-project
composer update devworktech/mdiag-dwt
php artisan config:clear
php artisan migrate --force
php artisan config:cache
sudo systemctl reload php8.4-fpm
```

Опубликованный конфиг не перезаписывается обновлением пакета; новые параметры добавляйте вручную.
Журналы: `storage/logs/laravel.log`, `/var/log/nginx/mdiag-error.log`.
Не отправляйте `.env`, токены и полный login-ответ в чат/публичные журналы.

В workflow сборки переименованы APK, package (`tech.devwork.mxdiag`) и signing secrets:
`MDIAG_KEYSTORE_BASE64`, `MDIAG_STORE_PASSWORD`, `MDIAG_KEY_ALIAS`, `MDIAG_KEY_PASSWORD`.
GitHub repository URL остаётся прежним. Android 32-bit ABI исходника не исправляется переименованием:
Pixel 8 Pro требует исходные arm64 библиотеки.

## 12. mX-DIAG 7.00.014 и выдача файлов через PHP

Новый репозиторий: **https://github.com/DevWorkTech/mdiag**.
Существующую копию исходников можно переключить, не меняя основной Laravel-проект:

```bash
cd /var/www/my-project/packages/DevWorkTech/MDiagRepository
git remote set-url origin https://github.com/DevWorkTech/mdiag.git
```

У нового репозитория отдельная история. Для чистой установки используйте `git clone` из раздела 1.
Если сохранили прежнюю рабочую копию, не делайте `reset --hard` поверх собственных правок:
скачайте новую копию в отдельную папку, перенесите свои настройки и обновите Composer path.

В GitHub → Actions → **Build mX-DIAG APK** задайте `lan_domain`, совпадающий с PHP-конфигом.
Вкладка Artifacts успешной сборки содержит `mX-DIAG-7.00.014.apk`, SHA-256 и отчёт ABI.
Имя приложения — **mX-DIAG**, applicationId — **tech.devwork.mxdiag**.
FileProvider имеет отдельный authority, данные хранятся в `mXDiagPro3`, поэтому установленный
оригинал не перезаписывается. Java-классы и алгоритмы входа/загрузки не переименовываются.
Ключ подписи следует сохранить в secrets нового репозитория (см. раздел 11): secrets между
репозиториями автоматически не переносятся. Без них CI выпускает тестовую сборку с новым ключом;
следующее обновление такой сборки может потребовать удаления предыдущей.

Исходник закреплён по SHA-256 вложенного APK:
`609b6fdaaf059a36b5442fce28e1341794912a4dd8378a468b1833c4f0c9c423`.
Официальная копия проверена и имеет тот же хеш. Это по-прежнему 32-битный `armeabi` APK;
64-битных native-библиотек для Pixel 8 Pro в нём нет.

Все `/xdiag/...` (и пути других профилей) направляются Nginx в Laravel, даже если одноимённый файл
случайно появился в `public/`. Архивы храните только под `storage/app/private/mdiag/packages`.
**Не создавайте symlink на пакеты в public, alias/static location или внешний CDN redirect.**
PHP проверяет локальную сессию, сроки пользователя и SN, флаги скачивания, разрешённую марку,
source_serial пакета и реальный путь. Только после этого BinaryFileResponse читает файл порциями.
`Range`, `HEAD` и неверный диапазон `416` обрабатываются PHP. Даже если другой модуль включает
X-Sendfile глобально, MDiag не делегирует выдачу Nginx. Каждый повторный запрос проверяет права заново;
уже начатая передача не прерывается посередине при изменении БД.

Штатные запросы 7.00.014 с HTTP-заголовками `cc`/`sign` и API-параметрами `user_id`/`sign`
проверяются по локальному токену. Нельзя разрешать скачивание только по SN или user_id.
Ошибка загрузки имеет HTTP 401/403/404 и JSON code/msg, чтобы клиент не записал текст ошибки в ZIP.
Штатный загрузчик может показывать общий HTTP-текст: отображение произвольного сообщения в каждом
экране без изменения UI не гарантируется.


## Если вход успешен, но SOAP возвращает Not Found

Обновите исходники компонента, затем из корня Laravel выполните:

```bash
composer dump-autoload
php artisan optimize:clear
php artisan mdiag:sync xdiag --list
```

CLI получает актуальные SOAP URL из `config_service.urls`. Адреса из ответа проверяются
перед передачей cc/sign; посторонний хост не принимается автоматически. Точный URL с
`?wsdl` используется как адрес POST, при этом WSDL не скачивается. Встроенные адреса
остаются резервом. Недоступность порта 8000 не означает ошибку пароля.

Если поставщик перенёс сервис на другой домен, в опубликованном `config/mdiag-dwt.php`
внутри `$xdiag['sync']` добавьте `soap_endpoints` с массивами `product`, `diagnostic`,
`public`. Каждый массив содержит проверенные вами полные HTTPS URL. Непустой массив
полностью заменяет автоматический выбор этого сервиса. После изменения — `php artisan config:clear`.
Не подставляйте случайные зеркала: на указанные адреса уйдёт подпись вашей официальной сессии.
Не публикуйте конфиг заново с --force поверх собственных настроек.

Если основной сервер продолжает отвечать 404 и актуальная конфигурация не возвращает
рабочий адрес, требуется URL из сетевого запроса оригинального приложения. Без проверки
на реальном аккаунте доступность закрытого сервиса подтвердить невозможно.

## Локальный TLS и диагностика входа планшета

`verify_tls=false` управляет только внешней CLI-синхронизацией. Эта настройка
не отключает TLS-проверку в Android. Для mX-DIAG предусмотрены пользовательский
CA и необязательный встроенный CA, доверие ограничено доменом сборки.

Если Android пишет «не удаётся открыть сертификат», сначала проверьте сам файл
и экспортируйте **существующий CA**, не генерируя новые ключи:
```bash
sudo openssl x509 -in /etc/nginx/mdiag-tls/mdiag-local-ca.crt -noout -subject -dates -ext basicConstraints
sudo openssl verify -CAfile /etc/nginx/mdiag-tls/mdiag-local-ca.crt /etc/nginx/mdiag-tls/diag.devwork.local.crt
sudo openssl x509 -in /etc/nginx/mdiag-tls/mdiag-local-ca.crt -outform DER -out /tmp/mdiag-local-ca.cer
sudo chmod 644 /tmp/mdiag-local-ca.cer
```
Передайте /tmp/mdiag-local-ca.cer на планшет как файл, сохраните в Downloads.
Устанавливайте через настройки безопасности → установка сертификата → **сертификат CA**,
а не «VPN/приложения» и не простым открытием вложения. Названия меню зависят от Android.
Нужен CA с CA:TRUE, не серверный leaf-сертификат. Приватный ключ не передавайте.
По одному сообщению Android причину сбоя импорта определить нельзя.

Вариант без ручной установки: создайте GitHub Actions secret **MDIAG_CA_CERT_PEM**
с полным содержимым публичного mdiag-local-ca.crt (BEGIN/END CERTIFICATE).
Запустите Build mX-DIAG APK с вашим lan_domain. Сборка включает сертификат
в res/raw, а domain-config доверяет ему только для этого имени. Без секрета
сборка по-прежнему требует установленный CA. Системный/пользовательский TLS trust
не гарантирует работу стороннего клиента с собственным TrustManager/pinning;
это проверяется на устройстве. Проверка имени и срока сертификата сохраняется.

Адреса SOAP планшет получает локально через:
`https://diag.devwork.local/xdiag/?action=config_service.urls`.
Bootstrap возвращает таблицу официальных ключей, но все URL ведут в локальный
профиль. В APK также есть локальная assets/configurl.json как начальная таблица.
Внешние зеркала CLI не должны попадать в ответы планшету. Прозрачное резервирование
одного домена организуется локальным DNS/reverse proxy, а не резервным входом
планшета на официальный сервер.

Для диагностики добавьте в `auth` опубликованного конфига:
```php
'diagnostic_log' => true,
```
Затем:
```bash
php artisan optimize:clear
tail -f storage/logs/mdiag-auth.log /var/log/nginx/mdiag-access.log
```
Повторите вход на планшете локальной учётной записью.
`success` подтверждает выдачу локальной сессии; `invalid_credentials` — неверный
локальный логин/пароль; `user_disabled` — блокировку; `subscription_expired` —
истечение срока; `internal_error` — ошибку сервера. Если нет HTTP-запроса в Nginx,
сначала проверяйте DNS/подключение/TLS на планшете. Наличие HTTP-запроса к другому
path не доказывает успешность login. После проверки выключите diagnostic_log.
Файл содержит только время и причину, без идентификаторов и секретов.
