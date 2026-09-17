# Серверный компонент MDiag DWT

Устанавливается через Composer path repository, автоматически подключает `MDiagServiceProvider`.
Ничего не копирует в `app/`, `routes/`, `users` основного проекта.

Папки:
- `Services/Local/`: локальная авторизация, правила доступа, SOAP/JSON, каталог ошибок.
- `Http/Controllers/`: входящий API без внешнего проксирования.
- `Services/Sync/`: официальный клиент только для CLI.
- `Console/`: управление пользователями/сканерами, импорт и синхронизация.
- `Models/`, `database/migrations/`: изолированные таблицы `mdiag_*`.
- `resources/`: локальные пути bootstrap-конфигурации APK.
- `tests/`: проверки границ доступа и отсутствия исходящих HTTP-запросов.

Полная инструкция: [../INSTALL_SERVER_RU.md](../INSTALL_SERVER_RU.md).
