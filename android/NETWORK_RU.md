# Сеть mX-DIAG 7.00.014-mdiag2

Полная инструкция обновления/диагностики: [REWORK_TRANSPORT_RU.md](../REWORK_TRANSPORT_RU.md).

Основной login выполняет OkHttp (j.x), а не Apache AsyncHttpClient. NetworkBridge
перехватывает newCall, меняет известный диагностический origin на
https://diag.devwork.tech/xdiag и сохраняет метод, query и тело запроса.
Адрес из старых preferences также нормализуется. Для локального хоста создаётся
копия клиента с системным TLS, двухаргументным sslSocketFactory(factory, trustManager)
и строгим OkHostnameVerifier. Внешние клиенты сохраняют исходные настройки.

LanTls.configureTrusted обслуживает дополнительный Apache-стек. Его наличие
в журнале само по себе не доказывает работу основного входа. Самоподписанный
сертификат рабочая сборка не принимает: нужен действительный сертификат
Let's Encrypt и fullchain.pem. APP_URL Laravel не используется для домена модуля.

Исходная проверка Интернета не отключается и не подменяется. Восстановлена
оригинальная политика cleartext HTTP; исходные проверки сравнивает CI.

## Диагностика основного входа

```bash
adb shell dumpsys package tech.devwork.mxdiag | grep versionName
adb logcat -v time -s System.err:* AndroidRuntime:* > mdiag-device.log
```

Нужная версия: 7.00.014-mdiag2. Воспроизведите вход, завершите Ctrl+C.
Ищите MDiagNetwork: login_prepare, local_okhttp_ready, request/response,
network_failed. ID сопоставляется с X-MDiag-Request-Id и серверным JSONL-журналом.
X-MDiag-Client=7.00.014-mdiag2 позволяет отличить запрос новой сборки.
Не публикуйте полный logcat: сторонние оригинальные классы могут писать секреты.
Новый MDiag-журнал не содержит тела, token, пароль или полный query.

## Постоянная подпись APK

Без signing secrets CI явно помечает release как ephemeral: каждый APK получает
новый ключ, Android может не разрешить обновление поверх предыдущего. Не удаляйте
данные приложения вслепую. Для постоянной подписи создайте/сохраните keystore
скриптом android/create_keystore.sh. В GitHub Settings -> Secrets and variables ->
Actions добавьте MDIAG_KEYSTORE_BASE64 (base64 файла), MDIAG_STORE_PASSWORD,
MDIAG_KEY_ALIAS и MDIAG_KEY_PASSWORD. Ключ и пароль не коммитьте в репозиторий.
Новый постоянный ключ не совпадёт с уже утраченным временным ключом предыдущей сборки.

## Что проверяет CI

Точный ABI обфусцированных классов в исходном APK; сохранность методов проверки
Интернета; APK build/sign/verify. JVM интеграция NetworkBridge использует адаптеры
точных имён APK к stock OkHttp 3.12: реальный POST/TLS, отказ при неверном hostname,
недоверенном сертификате, сохранение исходного внешнего клиента, execute/enqueue.
Это не тест реального Android UI/ART и не подтверждение работы всех экранов.
