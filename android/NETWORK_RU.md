# Актуальный TLS для diag.devwork.ru

Рабочая сборка вызывает LanTls.configureTrusted: системное доверие Android и проверка hostname для локального домена. Требуется действительный сертификат (например Let's Encrypt), полный chain и правильный DNS. BKS поставщика применяется только к внешним адресам этого Apache-клиента. Старый configure с исключением для самоподписанного сертификата используется лишь в тесте; APK его не вызывает.

CI проверяет, что рабочий режим отклоняет самоподписанный сертификат. Метод проверки сети и ping не изменены. APP_URL Laravel менять не нужно; измените $domain в опубликованном config/mdiag-dwt.php и Nginx.

Диагностика на планшете:

```bash
adb logcat -v time | grep -E 'MDiagTLS|SSLHandshake|UnknownHost|ConnectException'
```

Ожидаемая строка: Apache LAN adapter installed (insecure=false) for diag.devwork.ru. Следующая строка local TLS connection указывает попытку соединения. Это ещё не подтверждение успешного входа: проверьте Nginx и Laravel mdiag-debug. Не публикуйте полный logcat с токенами оригинального приложения.
