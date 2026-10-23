# game-cms

Mini CMS с конструктором 8-битных игр (flat-file, без СУБД). PHP 8.1+, zero-dependencies.

## Требования
- PHP 8.1+ с расширениями: gd (+webp), intl, libxml, fileinfo, mbstring, json, curl, zip
- Записываемые каталоги `data/` и `media/` для веб-процесса

## Быстрый старт (dev)
```bash
php cli/create-admin.php --username=admin --password='Adm1n-Str0ng!Pass'
php cli/seed-genres.php            # 5 жанров из фикстур config/genre-defaults
php cli/seed-ai-models.php         # 50 встроенных AI-моделей (config/ai-defaults)
php cli/seed-movement-patterns.php # 50 встроенных паттернов движения (config/pattern-defaults)
php -S 127.0.0.1:8099 -t public    # http://127.0.0.1:8099/admin/login.php
```

## Структура
- `public/` — точки входа (index, admin/*, api/*); единственный публичный каталог
- `src/` — код приложения (Storage, Auth, Audit, RateLimit, Http, Validation, Emoji, Library)
- `config/` — конфигурация и фикстуры (genre-defaults, ai-defaults, pattern-defaults)
- `data/` — flat-file данные (JSON + audit log); закрыт от веба (.htaccess)
- `media/` — файлы эмодзи; закрыт исполнение PHP (.htaccess)
- `cli/` — консольные команды (seed, create-admin, backup, restore)
- `tests/` — unit-тесты и smoke-тесты

## Тесты
```bash
php tests/run.php          # unit-тесты (нужен pcntl_fork для конкурентных тестов)
bash tests/smoke-http.sh   # HTTP smoke этапа 1 (нужен запущенный php -S на 8099)
bash tests/smoke-stage3.sh # HTTP smoke этапа 3 (библиотеки AI/паттернов)
```

## Деплой и бэкап
```bash
bash deploy.sh <host> <path>      # rsync + права
php cli/backup.php                # tar.gz снапшот data/ + media/ в data/backups
php cli/restore.php <archive>     # восстановление из архива
```

## Безопасность (кратко)
bcrypt (cost 12), CSRF-токены на всех POST, троттлинг логина 5/10 мин,
rate-limit API по IP, CSP/nosniff/X-Frame-Options, аудит JSON Lines с ротацией,
атомарная запись tmp→rename + flock, защита путей от directory traversal.
