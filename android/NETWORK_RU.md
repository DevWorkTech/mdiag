# Сеть mX-DIAG 7.00.014

Проверки ConnectivityManager и ping восстановлены из исходного APK. Патч больше
не меняет методы проверки сети и не привязывает процесс к Wi-Fi/Ethernet.
Адреса Google/Baidu/QQ/Apple диагностического экрана сохранены. Проверка доступности
сети самой ОС зависит от прошивки Android; её адреса определяются на роутере.

Источник APK: https://testnet-faucet.devwork.tech/X-DIAG_V7.00.014.apk.
В CI проверяется закреплённый SHA-256 исходного файла. Bootstrap и известные
HTTP API домены/IP заменяются на локальный профиль /xdiag. Голые SOAP namespace
не являются адресами запросов и сохраняются. Статический аудит не доказывает
перенаправление динамических URL, native-библиотек и сохранённых настроек.

## Вход не виден в Laravel

Repairdata использует WebView; авторизация использует другой HTTP-клиент.
Открытие WebView не подтверждает TLS/hostname verification в клиенте входа.
В APK 7.00.014 подтверждено: AsyncHttpClient создаёт SSLContext из встроенного BKS
поставщика. Это обходило CA из network_security_config. LanTls перенастраивает
именно этот Apache-клиент: для точного локального домена допускается локальный
сертификат без проверки цепочки и имени, как запрошено владельцем LAN. Для других
хостов остаётся исходный BKS и проверка имени. Проверки доступности сети не меняются.
Это режим доверенной LAN: TLS шифрует трафик, но не удостоверяет локальный сервер.

CI проверяет реальный handshake с самоподписанным сертификатом и неверным CN:
LAN-host проходит, другой hostname отклоняется. Затем проверяются сигнатуры Apache
в самом APK, helper компилируется в DEX и добавляется в основной smali.
Это не заменяет проверку на планшете. Другие HTTP-стеки APK этим адаптером не меняются.
В logcat строки MDiagTLS показывают установку адаптера, попытку подключения и класс
ошибки, без пароля/токена. CI сохраняет TLS-методы в network-before/after.json.

Сопоставьте время нажатия «Вход» в следующих журналах:

```bash
sudo tail -f /var/log/nginx/mdiag-access.log /var/log/nginx/mdiag-error.log
sudo -u www-data php artisan mdiag:doctor
adb logcat -v time | grep -Ei 'MDiagTLS|SSLHandshake|SSLPeer|CertPath|UnknownHost|ConnectException|SocketTimeout|Cleartext|AndroidRuntime'
```

Не публикуйте полный logcat: оригинальный клиент может записывать пароли/токены
в собственные журналы. Присылайте только исключение, хост и время без данных входа.
Если Nginx не видит запроса, изменение локального пароля или JSON-ответа Laravel
не исправит этот этап. При HTTP в access.log смотрите статус, затем MDiag trace.
APP_DEBUG=true включает storage/logs/mdiag-debug-YYYY-MM-DD.log; после изменения
настроек выполните php artisan optimize:clear. Отсутствие mdiag-auth.log не доказывает
отсутствие TCP/TLS: этот файл относится к обработанному входу.

Для изоляции TLS предусмотрена сборка workflow_dispatch с lan_scheme=http.
Ей должны соответствовать HTTP Nginx и scheme=http в config/mdiag-dwt.php.
Нельзя менять схему только с одной стороны. Постоянный HTTPS требует корректного
сертификата для домена и доверия используемого сетевого клиента.
