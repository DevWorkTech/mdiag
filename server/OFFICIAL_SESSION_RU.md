# Сессия официального PHP-клиента XDiag

После успешного входа token, user_id и CookieJar сохраняются зашифрованными в default cache Laravel. Ключ привязан к точному логину; смена пароля исключает повторное использование. Пароль в кеше не хранится. Сессии локальных планшетов остаются в своей БД и не используются для официальных запросов.

Повторный mdiag:sync восстанавливает сессию. Срок кеша official_session_ttl в config/mdiag-dwt.php: по умолчанию 7200 секунд, без продления от чтения. Это срок нашего кеша, не утверждение о сроке upstream-token. При досрочном отзыве сессии выполните выход, затем повторите команду; сетевые/ SOAP-ошибки сами по себе не вызывают повторный login.

```bash
php artisan mdiag:logout xdiag
php artisan mdiag:sync xdiag --list
```

mdiag:logout очищает сессию PHP-клиента без нового входа. Это не обещание отзыва token на стороне поставщика: протокол server logout пока проверяется.
Стандартный php artisan optimize:clear также удаляет сессию, так как вызывает очистку default cache. Он очищает и другой кеш Laravel — поведение самой команды не менялось.
Для сохранения между CLI-процессами используйте существующий Redis/database/file cache, не array/null. APP_KEY должен оставаться постоянным.

## Переход на diag.devwork.ru

В уже опубликованном config/mdiag-dwt.php установите $domain = 'diag.devwork.ru'; $scheme = 'https'. APP_URL основного сайта менять не нужно.
Обновите Nginx из deploy/nginx-mdiag.conf: сертификат /etc/letsencrypt/live/diag.devwork.ru/fullchain.pem, ключ privkey.pem. В примере убран прежний фильтр IP 192.168.88.0/24, мешающий проверке через интернет; ограничение Host и авторизация модуля остаются.

```bash
php artisan optimize:clear
sudo nginx -t
sudo systemctl reload nginx
curl -I https://diag.devwork.ru/xdiag/health
```

curl выполняется без -k: сертификат Let's Encrypt и цепочка должны проверяться. Проверьте DNS A/AAAA и доступность из сети планшета. Используйте новый APK под .ru; версия под .local сама адрес не изменит.
