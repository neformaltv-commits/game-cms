# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

**Структура документа (12 блоков):**
1. **Общее описание, архитектурные решения, технологический стек** ← текущий блок
2. Структура проекта и хранение данных
3. Безопасность и аутентификация
4. Админка: общее, жанры, категории
5. Библиотеки AI-моделей и паттернов движения
6. Банк эмодзи
7. Карточка игры и легенда
8. Уровни и редактор уровней
9. Управление, звуки, настройки движка, AI в легенде
10. Движки: архитектура, `static-screen`, `vertical-scroll`, `horizontal-scroll`, общие модули
11. Публичная часть и бэкапы
12. Тестирование, документация, критерии приёмки

---

# Блок 1. Общее описание, архитектурные решения, технологический стек

## 1. Общее описание

Лёгкая **flat-file CMS** (без СУБД, всё хранение — в JSON-файлах и медиафайлах) с веб-админкой для каталогизации и создания игр в эстетике и механике бытовых 8-битных компьютеров и приставок 80-х (ZX Spectrum, C64, NES, Atari 2600, Arcade).

**Конечный продукт позволяет без программирования:**

- создавать и публиковать игры;
- рисовать уровни;
- загружать кастомную графику (PNG, GIF, SVG);
- настраивать управление, звуки, физику;
- выбирать поведение мобов из библиотеки из 50 AI-моделей и 50 паттернов движения.

**Ключевая эстетическая рамка:** каждый уровень — это **экран ZX Spectrum 32×24 ячейки**. Все игры рендерятся в этом окне. Скролл-игры имеют мир больше экрана, но окно — всегда 32×24.

**Целевая аудитория:**

- **Администратор CMS** — не программист, создаёт игры через админку (жанры, карточки, легенды, уровни, управление, звуки).
- **Разработчик** — поддерживает CMS, расширяет движки, библиотеки AI и паттернов.
- **Игрок** — заходит на публичную страницу, играет в опубликованные игры.

**Объём проекта:** 13 шагов разработки (1, 2, 3, 4, 4.5, 5, 6, 7, 8, 9, 10, 11, 12). Финальный результат — CMS v1.0.0 с тремя универсальными движками, банком кастомных эмодзи, библиотеками AI/паттернов, полноценной админкой и публичным каталогом.

---

## 2. Ключевые архитектурные решения

### 2.1. Flat-file вместо СУБД

**Решение:** всё хранится в JSON-файлах и медиафайлах. Никакой MySQL/PostgreSQL/SQLite.

**Причины:**
- простая установка (не нужен отдельный сервис);
- тривиальное резервное копирование (архив каталога);
- прозрачность данных (JSON читается глазами);
- для целевого объёма (десятки игр, сотни уровней, тысячи эмодзи) производительности файловой системы достаточно.

**Ограничения (приняты осознанно):**
- нет транзакций на уровне нескольких файлов (частично компенсируется атомарной записью и валидацией перед записью);
- нет сложных запросов (фильтрация и сортировка — на уровне PHP-кода после чтения);
- конкурентная запись — через `flock` и атомарный `rename`.

**ADR:** `docs/adr/0001-flat-file-vs-database.md`.

### 2.2. Три движка по типу мира, а не по жанру

**Решение:** движок определяется **типом конструирования мира**, а не жанром игры.

| Движок | Мир | Обслуживаемые жанры |
|---|---|---|
| `static-screen` | Один экран 32×24, без скролла | Lode Runner-подобные, Arcade adventure (*Cauldron*, *Robin of the Wood*, *Atic Atac*), Pac-Man-подобные, Bomberman-подобные, Bubble Bobble-подобные, головоломки |
| `vertical-scroll` | Мир 32×N (N ≥ 24), вертикальный скролл | River Raid, Galaga, Xevious, Scramble, 1942, Twin Cobra |
| `horizontal-scroll` | Мир M×24 (M ≥ 32), горизонтальный скролл | Super Mario Bros., Myth, Wonder Boy, Ghosts'n Goblins, Chuckie Egg |

**Причины:**
- один движок `static-screen` покрывает Lode Runner, Cauldron, Bomberman, Pac-Man, Bubble Bobble — все они используют один экран;
- один движок `vertical-scroll` покрывает все вертикальные шутеры;
- один движок `horizontal-scroll` покрывает Mario, Myth, Wonder Boy — все они горизонтальные платформеры.

**Следствия:**
- **Все три движка универсальны и data-driven.** Поведение игры полностью определяется:
  - `genre.engine_config` — параметры движка жанра;
  - `game.engine_overrides` — переопределения на уровне игры;
  - `game.legend` — легенда игры (emoji → свойства);
  - `game.levels` — уровни;
  - `game.controls` — управление;
  - `game.sounds` — звуки.
- **Движок не содержит данных конкретной игры.** Новый жанр добавляется через создание игры и легенды, а не через код.
- **Разные жанры могут иметь одинаковый `engine`** и отличаться `engine_config` и `default_legend`. Это позволяет добавлять новые жанры без написания нового кода.

**ADR:** `docs/adr/0002-three-engines-by-world-type.md`.

### 2.3. Уровень = emoji-сетка

**Решение:** уровень — массив строк. Каждая строка — эмодзи, разделённые одним пробелом. Каждый эмодзи — один графемный кластер: Unicode, PUA-кодпоинт из банка или PUA-кодпоинт анимации.

**Единый формат для всех движков:**

| Движок | Размер сетки |
|---|---|
| `static-screen` | **24 строки × 32 emoji** (ровно) |
| `vertical-scroll` | **N строк × 32 emoji**, N ∈ [24, 500] |
| `horizontal-scroll` | **24 строки × M emoji**, M ∈ [32, 800] |

**Причины:**
- аутентичность ZX Spectrum (экран 32×24);
- визуальная простота редактора (сетка, а не спрайт-лист);
- читаемость JSON и текстового формата (bulk edit);
- переносимость между движками (одна сетка, разные интерпретации).

**ADR:** `docs/adr/0004-emoji-as-tiles.md`.

### 2.4. Богатство через данные

**Решение:** движки реализуют **общий набор механик**, активируемых через свойства тайлов и `engine_config`. Никакого программирования новой игры — только данные.

**Роли элементов (`role`):** `background`, `obstacle`, `collider`, `mob`, `pickup`, `exit`, `start`, `projectile`, `decor`.

**Свойства тайлов:** `solid`, `walkable`, `climbable`, `hangable`, `diggable`, `swimmable`, `harmful`, `bouncy`, `slippery`, `conveyor`, `one_way`, `pushable`, `portal_to`, `container`, `locked`, `breakable_by`, `explosive`, `damage_on_touch`.

**Действия:** `shoot`, `melee`, `spawn`, `open`, `heal`, `damage_area`, `teleport`, `freeze`, `push`, `throw`, `cast`, `use`, `on_stomp`, `on_touch`.

**Режимы мира:** `gravity` (вид сбоку, гравитация, прыжки), `top_down_4dir`, `top_down_8dir`, `top_down_4dir_no_diag`.

**Пример:** чтобы добавить Bubble Bobble-подобную игру, не нужно писать код. Нужно:
1. Создать игру с `genre_id`, у которого `engine: static-screen`.
2. Указать `engine_overrides: { movement_type: "gravity", physics.jump_power: -5.0 }`.
3. Определить легенду: `🧱` — `obstacle` + `solid`, `🐲` — `start`, `👾` — `mob`, `🫧` — `projectile`, `🍎` — `pickup`.
4. Настроить 10 звуков.
5. Нарисовать уровни.

Игра работает на существующем движке.

### 2.5. Составные объекты

**Решение:** объект игры (элемент легенды) может занимать несколько клеток сетки.

**Допустимые размеры (ширина × высота):** **1×1, 2×1, 1×2, 2×2, 3×2, 2×3.**

**Свойства:**
- каждая клетка отображается своим спрайтом из матрицы `composite.sprites`;
- объект — единая сущность для логики, коллизий, AI и взаимодействий;
- **якорь** — верхняя-левая клетка; уникален для элемента легенды.

**Причины:**
- 8-битные игры широко используют многотайловые объекты: боссы (*Castlevania*, *Contra*), крупные враги (*R-Type*, *Gradius*), двери и механизмы (*Zelda*, *Metroid*), составные персонажи (всадник на коне = 2×1, дракон = 3×2);
- до введения композитов объекты были ограничены 1×1 — это не покрывало перечисленные механики.

**ADR:** `docs/adr/0005-composite-objects.md`.

### 2.6. Банк эмодзи

**Решение:** самостоятельная подсистема для загрузки кастомных графических эмодзи.

**Поддерживаемые форматы:**
- **PNG** — пиксель-арт; опция `pixel_mode` (downscale до 16×16, апскейл до 64×64 nearest).
- **GIF** — берётся первый кадр, конвертируется в PNG.
- **SVG** — санитизируется до геометрии (фигуры + цвета + градиенты), **не растеризуется**, остаётся вектором.
- **Анимации** — последовательность существующих статичных эмодзи с индивидуальными длительностями.

**PUA-кодпоинты:** каждый эмодзи получает кодпоинт `U+E000`…`U+F8FF` (6400 слотов), который используется в легендах и сетках как ключ.

**Причины:**
- единый механизм для любых спрайтов (пиксель-арт, вектор, анимация);
- PUA-кодпоинты позволяют использовать кастомные эмодзи в emoji-сетке наравне с Unicode;
- SVG даёт масштабируемость (не теряет качество), PNG — аутентичный пиксель-арт.

**ADR:** `docs/adr/0003-svg-geometry-only.md`, `docs/adr/0006-pua-codepoints-for-bank.md`, `docs/adr/0007-animations-as-frame-sequences.md`.

### 2.7. Универсальные библиотеки AI и паттернов

**Решение:** две общие библиотеки — **50 AI-моделей** и **50 паттернов движения** — используются всеми тремя движками. Фильтрация по `supported_engines`.

**AI-модели** описывают **поведение** моба (преследование, патруль, стрельба, спавн, боссы).

**Паттерны движения** описывают **параметрическую траекторию** (синусоида, спираль, маятник, самонаведение).

**Три режима поведения моба:**
- `ai` — только AI;
- `trajectory` — только паттерн;
- `hybrid` — AI + модуляция паттерном.

**Причины:**
- в 8-битных играх враги делятся на «умных» (преследуют) и «траекторных» (летят по кривой) — библиотеки разделены;
- общая библиотека переиспользуется во всех движках, экономя реализацию;
- каждая модель/паттерн имеет параметры — админ настраивает поведение без кода.

**ADR:** `docs/adr/0007-animations-as-frame-sequences.md` (смежное).

### 2.8. Изоляция SVG через санитизацию до геометрии

**Решение:** SVG-файлы санитизируются до белого списка элементов (фигуры + градиенты) и атрибутов (геометрия + цвета + трансформации). Всё, что не является геометрией, удаляется. Затем SVG отдаётся с CSP и рендерится в движке через `<img>`.

**Разрешено:** `svg`, `g`, `defs`, `linearGradient`, `radialGradient`, `stop`, `rect`, `circle`, `ellipse`, `line`, `polyline`, `polygon`, `path`. Атрибуты — геометрия, `fill`/`stroke` (включая `url(#id)` на локальный градиент), `transform`, `id`, `opacity`.

**Запрещено (удаляется):** `text`, `image`, `use`, `foreignObject`, `script`, `style`, `animate*`, `filter`, `clipPath`, `mask`, `pattern`, `marker`, `symbol`, `iframe`, `audio`, `video`, `on*`, `xlink:*`, `href`, `class`, `src`, `xml:base`, `url()` на внешние ресурсы, `currentColor`.

**Двойная защита:**
1. **Санитизация** — в файле остаётся только геометрия.
2. **`<img>`-рендер** — браузер не исполняет скрипты и не загружает внешние ресурсы внутри SVG, загруженного через `<img>`.
3. **CSP + `nosniff` + `X-Frame-Options: DENY`** при отдаче — защита при прямом открытии по URL.

**Причины:**
- SVG — вектор, масштабируется без потерь;
- полный запрет SVG — потеря гибкости;
- растеризация SVG — потеря векторной масштабируемости;
- санитизация до геометрии + `<img>` — безопасно и сохраняет масштабируемость.

**ADR:** `docs/adr/0003-svg-geometry-only.md`.

### 2.9. Rate-limiting на файлах

**Решение:** rate-limiting реализуется в файловом хранилище — `data/rate-limit/{sha1(ip)}.json` с `{window_start, count}`. Никакого Redis/Memcached.

**Причины:**
- соответствует flat-file архитектуре;
- достаточная производительность для целевого объёма;
- нет зависимостей.

**Лимиты:**
- `GET /play/*` — 60/мин на IP;
- `GET /api/emoji-bank.json` — 120/мин на IP;
- остальные публичные страницы — 120/мин на IP.

**ADR:** `docs/adr/0008-rate-limiting-file-based.md`.

---

## 3. Технологический стек

| Компонент | Требование |
|---|---|
| Язык сервера | PHP 8.1+ |
| Расширения PHP | `gd` (с WebP) или `imagick`, `intl` (grapheme), `libxml` (DOMDocument с защитой от XXE), `fileinfo`, `json`, `session` |
| Хранение | JSON-файлы и медиафайлы |
| Аутентификация | Сессии + `password_hash(PASSWORD_BCRYPT)` |
| Фронтенд админки | Server-rendered + минимальный JS (визуальный редактор уровней на Canvas) |
| Публичная часть | Server-rendered + JS-движки на Canvas |
| Звук | Web Audio API |
| Зависимости | Нулевые (Composer опционально) |
| Требование к каталогам | `/data/` и `/config/` — вне web-root или закрыты `.htaccess`/nginx |
| Веб-сервер | Apache 2.4+ или nginx 1.18+ |
| Браузер (админка) | Chrome 90+, Firefox 90+, Safari 14+, Edge 90+ |
| Браузер (публичная часть) | Chrome 80+, Firefox 80+, Safari 13+, Edge 80+ |

**Требование к браузеру:** поддержка `Canvas 2D`, `Web Audio API`, `Intl.Segmenter` (для графемных кластеров), `requestAnimationFrame`.

**Проверка окружения (README):**

```bash
php -r "echo PHP_VERSION; echo PHP_EOL;"
php -r "echo extension_loaded('gd') && function_exists('imagewebp') ? 'gd-webp ok' : 'no';"
php -r "echo extension_loaded('intl') ? 'intl ok' : 'no';"
php -r "echo extension_loaded('libxml') ? 'libxml ok' : 'no';"
php -r "echo extension_loaded('fileinfo') ? 'fileinfo ok' : 'no';"
```

Все проверки должны вернуть `ok`.

---

**Конец блока 1 из 12.**

Следующий блок — **Структура проекта и хранение данных** (полное дерево каталогов, `FileStore`, схемы `genres.json`, `categories.json`, `games/*.json`, `emoji-bank.json`, `ai-models.json`, `movement-patterns.json`, `stats.json`, `audit.log`).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 2. Структура проекта и хранение данных

## 1. Структура проекта

```
project-root/
├── public/                          # web-root
│   ├── index.php                    # роутер публичной части
│   ├── .htaccess                    # (Apache) защита /data/, /config/, правила роутинга
│   ├── admin/                       # контроллеры админки (через роутер)
│   │   ├── login.php
│   │   ├── logout.php
│   │   ├── dashboard.php
│   │   ├── genres.php
│   │   ├── categories.php
│   │   ├── games.php
│   │   ├── ai-models.php
│   │   ├── patterns.php
│   │   ├── emoji-bank.php
│   │   ├── users.php
│   │   └── log.php
│   ├── api/
│   │   └── emoji-bank.php           # GET /api/emoji-bank.json
│   ├── media/                       # публичные медиа
│   │   ├── {game-id}/
│   │   │   ├── cover.webp           # обложка 640×480
│   │   │   ├── cover-prev.webp      # предыдущая обложка
│   │   │   └── level-XXX-thumb.webp # превью уровня 320×240
│   │   └── emoji-bank/              # файлы банка эмодзи
│   │       ├── e001.png
│   │       ├── e002.svg
│   │       └── ...
│   └── assets/
│       ├── admin.css
│       ├── admin.js
│       ├── public.css
│       └── engines/
│           ├── registry.js
│           ├── boot.js
│           ├── shared/
│           │   ├── engine-base.js
│           │   ├── audio-core.js
│           │   ├── input-core.js
│           │   ├── renderer-core.js
│           │   ├── legend-resolver.js
│           │   ├── level-parser.js
│           │   ├── ai-core.js
│           │   ├── patterns.js
│           │   ├── actions.js
│           │   └── validators.js
│           ├── static-screen/
│           │   ├── index.js
│           │   ├── engine.js
│           │   └── rules.js
│           ├── vertical-scroll/
│           │   ├── index.js
│           │   ├── engine.js
│           │   └── procedural.js
│           └── horizontal-scroll/
│               ├── index.js
│               ├── engine.js
│               └── camera.js
├── admin/                           # серверные контроллеры (роутер указывает сюда)
├── src/
│   ├── Storage/
│   │   ├── FileStore.php            # атомарное чтение/запись JSON
│   │   └── Atomic.php               # flock + rename
│   ├── Auth/
│   │   ├── Authenticator.php
│   │   └── Csrf.php
│   ├── Svg/
│   │   └── GeometrySvgSanitizer.php
│   ├── Emoji/
│   │   ├── EmojiProcessor.php       # PNG/GIF обработка
│   │   ├── SvgProcessor.php         # санитизация SVG
│   │   └── AnimationValidator.php
│   ├── Validation/
│   │   ├── GenreValidator.php
│   │   ├── CategoryValidator.php
│   │   ├── GameValidator.php
│   │   ├── LegendValidator.php
│   │   ├── LevelValidator.php
│   │   └── SettingsValidator.php
│   ├── Audit/
│   │   └── Logger.php
│   ├── RateLimit/
│   │   └── Limiter.php
│   ├── View/
│   │   └── Template.php
│   └── bootstrap.php
├── data/                            # вне web-root
│   ├── users.json
│   ├── genres.json
│   ├── categories.json
│   ├── games/
│   │   └── {game-id}.json
│   ├── emoji-bank.json
│   ├── emoji-bank-counter.json
│   ├── ai-models.json
│   ├── movement-patterns.json
│   ├── throttle.json                # защита от брутфорса
│   ├── rate-limit/                  # файлы rate-limiting
│   │   └── {sha1(ip)}.json
│   ├── stats.json                   # опционально
│   ├── audit.log
│   ├── play.log                     # опционально
│   └── backup.log
├── config/
│   ├── config.php
│   ├── secrets.php                  # вне git
│   ├── secrets.example.php
│   ├── genre-defaults/
│   │   ├── loderunner.json
│   │   ├── arcade-adventure.json
│   │   ├── platformer.json
│   │   ├── myth.json
│   │   └── riverraid.json
│   ├── ai-defaults/
│   │   ├── movement.json
│   │   ├── pursuit.json
│   │   ├── combat.json
│   │   ├── advanced.json
│   │   └── boss.json
│   └── pattern-defaults/
│       ├── linear.json
│       ├── oscillating.json
│       ├── curved.json
│       ├── homing.json
│       ├── composite.json
│       └── bit8-classic.json
├── cli/
│   ├── create-admin.php
│   ├── seed-genres.php
│   ├── seed-ai-models.php
│   ├── seed-movement-patterns.php
│   ├── backup.php
│   ├── restore.php
│   └── validate-levels.php
├── tests/
│   ├── Storage/
│   ├── Auth/
│   ├── Svg/
│   ├── Emoji/
│   ├── Engines/
│   ├── Validation/
│   ├── Public/
│   └── run.php
├── docs/
│   ├── adr/
│   │   ├── 0001-flat-file-vs-database.md
│   │   ├── 0002-three-engines-by-world-type.md
│   │   ├── 0003-svg-geometry-only.md
│   │   ├── 0004-emoji-as-tiles.md
│   │   ├── 0005-composite-objects.md
│   │   ├── 0006-pua-codepoints-for-bank.md
│   │   ├── 0007-animations-as-frame-sequences.md
│   │   └── 0008-rate-limiting-file-based.md
│   └── PROJECT-REPORT.md
├── README.md
├── USER-GUIDE.md
├── DEV-GUIDE.md
├── SECURITY.md
├── CHANGELOG.md
├── VERSION                          # "1.0.0"
├── .gitignore                       # /data/, /config/secrets.php, /public/media/*
├── .htaccess                        # защита /data/ и /config/
└── nginx.sample.conf
```

### 1.1. Изоляция каталогов

| Каталог | Расположение | Доступ из web |
|---|---|---|
| `/public/` | web-root | Полный (это и есть сайт) |
| `/data/` | вне web-root | Запрещён (Apache/nginx + `.htaccess`) |
| `/config/` | вне web-root | Запрещён |
| `/public/media/` | внутри web-root | Разрешён (медиафайлы), но исполнение PHP запрещено |
| `/public/media/emoji-bank/` | внутри web-root | Разрешён с CSP, `nosniff`, `X-Frame-Options: DENY` |
| `/src/`, `/admin/`, `/cli/`, `/tests/`, `/docs/` | вне web-root | Запрещён |

### 1.2. `.gitignore`

```
/data/
/config/secrets.php
/public/media/*
!/public/media/.gitkeep
/vendor/
/tests/.phpunit.result.cache
```

### 1.3. Пример nginx-конфига (`nginx.sample.conf`)

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/cms/public;
    index index.php;

    # Запрет доступа к служебным каталогам (если они внутри web-root)
    location ~ ^/(data|config|src|admin|cli|tests|docs)/ {
        deny all;
    }

    # Медиа: исполнение PHP запрещено
    location /media/ {
        location ~ \.(php|phtml|sh|html|htm)$ { deny all; }
        add_header X-Content-Type-Options "nosniff" always;
    }

    # Банк эмодзи: жёсткие заголовки для SVG
    location /media/emoji-bank/ {
        types { image/svg+xml svg; }
        add_header Content-Security-Policy "default-src 'none'; img-src data:;" always;
        add_header X-Content-Type-Options "nosniff" always;
        add_header X-Frame-Options "DENY" always;
    }

    # Роутинг
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.1-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }
}
```

### 1.4. Пример Apache `.htaccess` (корневой)

```apache
# Запрет доступа к служебным каталогам
RedirectMatch 404 ^/(data|config|src|admin|cli|tests|docs)/

# Роутинг
<IfModule mod_rewrite.c>
    RewriteEngine On
    RewriteCond %{REQUEST_FILENAME} !-f
    RewriteCond %{REQUEST_FILENAME} !-d
    RewriteRule ^ index.php [QSA,L]
</IfModule>

# Заголовки безопасности
<IfModule mod_headers.c>
    Header always set X-Content-Type-Options "nosniff"
</IfModule>
```

### 1.5. `.htaccess` в `/public/media/`

```apache
php_flag engine off
RemoveHandler .php .phtml .sh .html .htm

<FilesMatch "\.svg$">
    ForceType image/svg+xml
    Header always set Content-Security-Policy "default-src 'none'; img-src data:;"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
</FilesMatch>
```

### 1.6. `config/config.php`

```php
<?php
return [
    'base_url'         => 'https://example.com',
    'data_dir'         => __DIR__ . '/../data',
    'media_dir'        => __DIR__ . '/../public/media',
    'config_dir'       => __DIR__,
    'session_timeout'  => 3600,       // сек неактивности
    'analytics_enabled'=> true,
    'audit_play_events'=> false,
    'audit_public_events'=> false,
    'trusted_proxies'  => [],         // для X-Forwarded-For
];
```

### 1.7. `config/secrets.php`

```php
<?php
return [
    'session_secret' => '<random 64 hex>',
    'app_pepper'     => '<random 64 hex>', // опционально
];
```

---

## 2. Слой хранилища: `FileStore`

### 2.1. Публичный API

```php
namespace App\Storage;

class FileStore
{
    public function __construct(string $rootDir);

    /**
     * Читает JSON-файл.
     * @param string $relativePath  относительно $rootDir (например, "games/game-001.json")
     * @param mixed  $default       возвращается, если файла нет
     * @return mixed
     * @throws StorageException если JSON битый
     */
    public function read(string $relativePath, mixed $default = []): mixed;

    /**
     * Атомарно записывает JSON-файл.
     * @throws StorageException при ошибке записи или сериализации
     */
    public function write(string $relativePath, mixed $data): void;

    /** Проверяет существование файла. */
    public function exists(string $relativePath): bool;

    /** Атомарно удаляет файл (с блокировкой). */
    public function delete(string $relativePath): void;

    /**
     * Транзакция: читает, применяет $mutator, пишет.
     * $mutator принимает &$data, возвращает bool (true — сохранить).
     * @throws StorageException при ошибке
     */
    public function mutate(string $relativePath, callable $mutator): void;
}
```

### 2.2. Требования к `write`

1. **Валидация пути:** только `[a-zA-Z0-9._/-]+`, без `..`, без ведущего `/`, без `\0`.
2. **Сериализация:**
   ```php
   json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
   ```
3. **Атомарность:**
   - запись во временный файл `$path . '.tmp.' . uniqid('', true)`;
   - `fsync` временного файла (опционально);
   - `rename()` временного файла в целевой путь (атомарно на POSIX);
   - при ошибке `rename` — удаление временного.
4. **Блокировка:** `flock(LOCK_EX)` на отдельном lock-файле `$path . '.lock'` на время операции (и при чтении, и при записи).
5. **Ошибка валидации JSON:** если `json_last_error() !== JSON_ERROR_NONE`, бросить `StorageException` — файл **не перезаписывается**.
6. **Создание каталогов:** если родительский каталог не существует — `mkdir($dir, 0755, true)`.

### 2.3. Требования к `read`

1. Если файла нет — вернуть `$default`.
2. Если JSON битый — бросить `StorageException` с указанием пути (не «тихо» возвращать пустое).
3. `flock(LOCK_SH)` на время чтения.

### 2.4. `mutate` — транзакционная операция

```php
public function mutate(string $path, callable $mutator): void
{
    $lock = fopen($this->rootDir . '/' . $path . '.lock', 'c');
    flock($lock, LOCK_EX);
    try {
        $data = $this->read($path);
        if ($mutator($data) !== false) {
            $this->write($path, $data);
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}
```

Используется везде, где нужно **прочитать → проверить → изменить → записать** атомарно (например, при удалении игры с проверкой целостности, при массовых операциях).

### 2.5. Тестовые требования

- 1000 параллельных записей из 10 процессов — файл не повреждён.
- Чтение несуществующего файла — `$default`.
- Битый JSON — `StorageException`.
- Path traversal (`../../etc/passwd`) — `StorageException`.
- `mutate` с исключением в `$mutator` — файл не изменён.

---

## 3. Схемы JSON — `users.json`

```json
[
  {
    "id": "user-001",
    "username": "admin",
    "password_hash": "$2y$10$...",
    "role": "admin",
    "created_at": "2026-10-21T10:00:00Z",
    "last_login_at": null
  }
]
```

**Правила:**
- `id`: `^user-\d{3,}$`.
- `username`: 3–40, `[a-zA-Z0-9_-]+`, уникален.
- `password_hash`: `password_hash(PASSWORD_BCRYPT)`.
- `role`: `admin` (единственная роль).
- Открытых паролей нет нигде.

---

## 4. Схема `genres.json`

5 жанров. Каждый жанр содержит полный `engine_config`, `default_legend`, `default_controls`, `default_sounds`.

```json
[
  {
    "id": "genre-loderunner",
    "slug": "lode-runner",
    "name": "Lode Runner-подобные",
    "description": "Одноэкранные головоломки с копанием ям, сбором золота и лестницами",
    "engine": "static-screen",
    "sort_order": 10,
    "created_at": "2026-10-21T10:00:00Z",
    "updated_at": "2026-10-21T10:00:00Z",
    "engine_config": {
      "tile_size": 8,
      "movement_type": "gravity",
      "view_mode": "side",
      "physics": {
        "gravity": 0.35,
        "move_speed": 1.6,
        "jump_power": 0,
        "climb_speed": 1.2,
        "swim_speed": 1.0,
        "coyote_time_ms": 100,
        "jump_buffer_ms": 150,
        "diagonal_movement": false,
        "max_fall_speed": 8.0
      },
      "combat": {
        "invulnerability_ms": 1000,
        "player_melee_cooldown_ms": 300,
        "enemy_contact_damage": 1
      },
      "player": {
        "lives": 3,
        "health_max": 1,
        "score_on_pickup": 10,
        "score_on_enemy_kill": 100
      },
      "hole_duration_ms": 3000,
      "trap_duration_ms": 2000,
      "speed_scale": 1.0,
      "sprite_flicker": false,
      "sprite_flicker_intensity": 0.1,
      "input_responsiveness": 1.0,
      "sound_volume": 0.03,
      "sound_enabled": true,
      "fps": 60,
      "show_hud": true,
      "animation_speed_scale": 1.0,
      "level_rules": {
        "requires_start": true,
        "requires_exit": true,
        "min_pickups": 1
      },
      "default_controls": {
        "left": ["ArrowLeft", "KeyA"],
        "right": ["ArrowRight", "KeyD"],
        "up": ["ArrowUp", "KeyW"],
        "down": ["ArrowDown", "KeyS"],
        "dig_left": ["KeyZ"],
        "dig_right": ["KeyX"],
        "pause": ["Escape"],
        "start": ["Enter"]
      },
      "default_sounds": [
        {"event": "dig",    "wave": "square",   "frequency_hz": 440,  "duration_ms": 50,  "volume": 0.03, "envelope": "decay"},
        {"event": "pickup", "wave": "square",   "frequency_hz": 1320, "duration_ms": 60,  "volume": 0.04, "envelope": "pluck"},
        {"event": "hit",    "wave": "square",   "frequency_hz": 220,  "duration_ms": 80,  "volume": 0.04, "envelope": "decay"},
        {"event": "death",  "wave": "sawtooth", "frequency_hz": 220,  "frequency_end_hz": 55, "duration_ms": 400, "volume": 0.06, "envelope": "linear"},
        {"event": "win",    "wave": "square",   "frequency_hz": 1760, "duration_ms": 250, "volume": 0.05, "envelope": "pluck"}
      ]
    },
    "default_legend": {
      "🧱": {"role": "obstacle", "name": "Кирпич", "solid": true, "diggable": true},
      "⬜": {"role": "obstacle", "name": "Бетон", "solid": true},
      "🪜": {"role": "obstacle", "name": "Лестница", "climbable": true},
      "〰️": {"role": "obstacle", "name": "Перекладина", "hangable": true},
      "🏃": {"role": "start",    "name": "Игрок"},
      "💰": {"role": "pickup",   "name": "Золото", "pickup_id": "gold", "effect": "score", "value": 10},
      "🚪": {"role": "exit",     "name": "Выход", "mode": "after_all"}
    }
  },
  {
    "id": "genre-arcade-adventure",
    "slug": "arcade-adventure",
    "name": "Arcade adventure",
    "engine": "static-screen",
    "sort_order": 20,
    "engine_config": {
      "tile_size": 8,
      "movement_type": "top_down_8dir",
      "view_mode": "top",
      "physics": {
        "gravity": 0,
        "move_speed": 1.6,
        "jump_power": 0,
        "diagonal_movement": true
      },
      "combat": {
        "invulnerability_ms": 1500,
        "player_melee_cooldown_ms": 300,
        "enemy_contact_damage": 1
      },
      "player": {
        "lives": 3,
        "health_max": 5,
        "score_on_pickup": 10,
        "score_on_enemy_kill": 100
      },
      "speed_scale": 1.0,
      "sprite_flicker": false,
      "sprite_flicker_intensity": 0.1,
      "input_responsiveness": 1.0,
      "sound_volume": 0.03,
      "sound_enabled": true,
      "fps": 60,
      "show_hud": true,
      "animation_speed_scale": 1.0,
      "level_rules": {"requires_start": true, "requires_exit": true, "min_pickups": 1},
      "default_controls": {
        "left": ["ArrowLeft", "KeyA"],
        "right": ["ArrowRight", "KeyD"],
        "up": ["ArrowUp", "KeyW"],
        "down": ["ArrowDown", "KeyS"],
        "fire": ["Space"],
        "action": ["KeyE"],
        "pause": ["Escape"],
        "start": ["Enter"]
      },
      "default_sounds": []
    },
    "default_legend": {
      "🟩": {"role": "background", "name": "Трава", "walkable": true},
      "🌳": {"role": "obstacle", "name": "Дерево", "solid": true},
      "🏹": {"role": "start", "name": "Игрок"},
      "➡️": {"role": "projectile", "name": "Стрела", "speed": 4, "damage": 1, "lifetime_ms": 2000},
      "🚪": {"role": "exit", "name": "Дверь", "mode": "touch"}
    }
  },
  {
    "id": "genre-platformer",
    "slug": "platformer",
    "name": "Платформеры",
    "engine": "horizontal-scroll",
    "sort_order": 30,
    "engine_config": {
      "tile_size": 8,
      "camera_mode": "follow",
      "camera_lerp": 0.15,
      "physics": {
        "gravity": 0.35,
        "move_speed": 1.6,
        "jump_power": -5.2,
        "max_fall_speed": 8.0,
        "coyote_time_ms": 100,
        "jump_buffer_ms": 150,
        "variable_jump": true,
        "double_jump": false,
        "air_control": 0.8
      },
      "combat": {"invulnerability_ms": 1000, "enemy_contact_damage": 1},
      "player": {"lives": 3, "health_max": 1, "score_on_pickup": 10, "score_on_enemy_kill": 100},
      "speed_scale": 1.0,
      "sprite_flicker": false,
      "sprite_flicker_intensity": 0.1,
      "input_responsiveness": 1.0,
      "sound_volume": 0.03,
      "sound_enabled": true,
      "fps": 60,
      "show_hud": true,
      "animation_speed_scale": 1.0,
      "level_rules": {"requires_start": true, "requires_finish": true, "min_width_tiles": 32, "max_width_tiles": 800},
      "default_controls": {
        "left": ["ArrowLeft", "KeyA"],
        "right": ["ArrowRight", "KeyD"],
        "up": ["ArrowUp", "KeyW"],
        "down": ["ArrowDown", "KeyS"],
        "jump": ["Space", "KeyZ"],
        "fire": ["KeyX"],
        "pause": ["Escape"],
        "start": ["Enter"]
      },
      "default_sounds": []
    },
    "default_legend": {
      "🟫": {"role": "obstacle", "name": "Земля", "solid": true},
      "🟩": {"role": "background", "name": "Трава", "walkable": true},
      "🏃": {"role": "start", "name": "Игрок"},
      "🚩": {"role": "exit", "name": "Флаг", "mode": "touch"}
    }
  },
  {
    "id": "genre-myth",
    "slug": "myth-like",
    "name": "Экшен-платформеры с горизонтальным скроллингом",
    "engine": "horizontal-scroll",
    "sort_order": 40,
    "engine_config": {
      "tile_size": 8,
      "camera_mode": "follow",
      "camera_lerp": 0.15,
      "physics": {
        "gravity": 0.35, "move_speed": 1.6, "jump_power": -5.2,
        "max_fall_speed": 8.0, "coyote_time_ms": 100, "jump_buffer_ms": 150,
        "variable_jump": true, "double_jump": false, "air_control": 0.8
      },
      "combat": {"invulnerability_ms": 1000, "player_melee_cooldown_ms": 400, "enemy_contact_damage": 1},
      "player": {"lives": 3, "health_max": 5, "score_on_pickup": 10, "score_on_enemy_kill": 100},
      "speed_scale": 1.0, "sprite_flicker": false, "sprite_flicker_intensity": 0.1,
      "input_responsiveness": 1.0, "sound_volume": 0.03, "sound_enabled": true,
      "fps": 60, "show_hud": true, "animation_speed_scale": 1.0,
      "level_rules": {"requires_start": true, "requires_finish": true, "min_width_tiles": 32, "max_width_tiles": 800},
      "default_controls": {
        "left": ["ArrowLeft", "KeyA"], "right": ["ArrowRight", "KeyD"],
        "up": ["ArrowUp", "KeyW"], "down": ["ArrowDown", "KeyS"],
        "jump": ["Space", "KeyZ"], "fire": ["KeyX"],
        "pause": ["Escape"], "start": ["Enter"]
      },
      "default_sounds": []
    },
    "default_legend": {
      "🏛": {"role": "background", "name": "Пол", "walkable": true},
      "🗿": {"role": "obstacle", "name": "Колонна", "solid": true},
      "🏃": {"role": "start", "name": "Герой"},
      "🚪": {"role": "exit", "name": "Выход", "mode": "touch"}
    }
  },
  {
    "id": "genre-riverraid",
    "slug": "vertical-shooter",
    "name": "Вертикальные скролл-шутеры",
    "engine": "vertical-scroll",
    "sort_order": 50,
    "engine_config": {
      "tile_size": 8,
      "scroll_direction": "up",
      "scroll_speed": 2.0,
      "physics": {"move_speed": 3.0, "vertical_move_speed": 2.0, "vertical_range": 5, "boundary_behavior": "clamp"},
      "combat": {"player_fire_rate_ms": 150, "invulnerability_ms": 1000, "enemy_contact_damage": 1},
      "player": {"lives": 3, "health_max": 1, "fuel_capacity": 100, "fuel_consumption_per_sec": 1.5, "score_on_enemy_kill": 100},
      "speed_scale": 1.0, "sprite_flicker": false, "sprite_flicker_intensity": 0.1,
      "input_responsiveness": 1.0, "sound_volume": 0.03, "sound_enabled": true,
      "fps": 60, "show_hud": true, "animation_speed_scale": 1.0,
      "level_rules": {"requires_start": true, "requires_exit": false, "min_pickups": 0},
      "default_controls": {
        "left": ["ArrowLeft", "KeyA"], "right": ["ArrowRight", "KeyD"],
        "up": ["ArrowUp", "KeyW"], "down": ["ArrowDown", "KeyS"],
        "fire": ["Space", "KeyZ"],
        "pause": ["Escape"], "start": ["Enter"]
      },
      "default_sounds": []
    },
    "default_legend": {
      "🌊": {"role": "background", "name": "Вода", "walkable": true},
      "🏞": {"role": "obstacle", "name": "Берег", "solid": true},
      "✈️": {"role": "start", "name": "Самолёт"},
      "💥": {"role": "projectile", "name": "Пуля", "speed": 6, "damage": 1, "lifetime_ms": 2000},
      "⛽": {"role": "pickup", "name": "Заправка", "pickup_id": "fuel", "effect": "ammo", "value": 50}
    }
  }
]
```

**Правила:**

| Поле | Правило |
|---|---|
| `id` | `^genre-[a-z0-9-]+$`, иммутабелен |
| `slug` | `^[a-z0-9-]{2,40}$`, уникален |
| `name` | 2–80 |
| `description` | 0–500 |
| `engine` | `static-screen` \| `vertical-scroll` \| `horizontal-scroll` |
| `sort_order` | целое |
| `engine_config` | по схеме движка (см. блок 10) |
| `default_legend` | объект emoji → элемент (см. блок 7) |
| `default_controls` | объект action → массив `KeyboardEvent.code` |
| `default_sounds` | массив ≤ 10 (см. блок 9) |

**Смена `engine`:** модалка-предупреждение; после подтверждения `engine_config`, `default_legend`, `default_controls`, `default_sounds` **перезаписываются** значениями нового движка из фикстуры; запись в `audit.log`.

**Удаление:** блокируется, если жанр используется хотя бы одной игрой. Подтверждение — ввод slug.

---

## 5. Схема `categories.json`

```json
[
  {
    "id": "cat-arcade",
    "slug": "arcade",
    "name": "Аркады",
    "description": "Быстрые игры с простым управлением",
    "parent_id": null,
    "sort_order": 10,
    "created_at": "2026-10-21T10:00:00Z",
    "updated_at": "2026-10-21T10:00:00Z"
  },
  {
    "id": "cat-shooter",
    "slug": "shooter",
    "name": "Шутеры",
    "description": "",
    "parent_id": "cat-arcade",
    "sort_order": 10,
    "created_at": "2026-10-21T10:00:00Z",
    "updated_at": "2026-10-21T10:00:00Z"
  }
]
```

**Правила:**

| Поле | Правило |
|---|---|
| `id` | `^cat-[a-z0-9-]+$`, иммутабелен |
| `slug` | `^[a-z0-9-]{2,40}$`, уникален |
| `name` | 2–80 |
| `description` | 0–500 |
| `parent_id` | `null` или существующий `id`; не self; без циклов; глубина ≤ 5 |
| `sort_order` | целое, уникален в рамках родителя |

**Удаление:** блокируется при наличии дочерних категорий или использовании в играх. Подтверждение — ввод slug.

---

## 6. Схема `games/{game-id}.json`

```json
{
  "id": "game-001",
  "slug": "zoloto-bungelingov",
  "title": "Золото Бунгелингов",
  "genre_id": "genre-loderunner",
  "description": "Одноэкранная головоломка: соберите всё золото и уйдите через выход.",
  "cover": {
    "path": "media/game-001/cover.webp",
    "width": 640,
    "height": 480,
    "alt": "Обложка: пещера с золотом",
    "uploaded_at": "2026-10-21T10:00:00Z"
  },
  "category_ids": ["cat-puzzle", "cat-arcade"],
  "status": "published",
  "controls": {
    "left": ["ArrowLeft", "KeyA"],
    "right": ["ArrowRight", "KeyD"],
    "up": ["ArrowUp", "KeyW"],
    "down": ["ArrowDown", "KeyS"],
    "dig_left": ["KeyZ"],
    "dig_right": ["KeyX"],
    "pause": ["Escape"],
    "start": ["Enter"]
  },
  "sounds": [
    {"event": "dig",    "wave": "square", "frequency_hz": 440,  "duration_ms": 50, "volume": 0.03, "envelope": "decay"},
    {"event": "pickup", "wave": "square", "frequency_hz": 1320, "duration_ms": 60, "volume": 0.04, "envelope": "pluck"}
  ],
  "engine_overrides": {
    "speed_scale": 1.1,
    "sprite_flicker": true
  },
  "legend": {
    "🧱": {"role": "obstacle", "name": "Кирпич", "solid": true, "diggable": true},
    "🏃": {"role": "start", "name": "Игрок"},
    "💰": {"role": "pickup", "name": "Золото", "pickup_id": "gold", "effect": "score", "value": 10},
    "🚪": {"role": "exit", "name": "Выход", "mode": "after_all"}
  },
  "levels": [
    {
      "level_id": "level-001",
      "name": "Вход в шахту",
      "order": 1,
      "grid": [
        "🧱 🧱 🧱 ... 🧱",
        "..."
      ],
      "thumb": "media/game-001/level-001-thumb.webp",
      "params": {"time_limit_seconds": 120},
      "created_at": "2026-10-21T15:00:00Z",
      "updated_at": "2026-10-21T15:30:00Z"
    }
  ],
  "meta": {
    "seo_title": "",
    "seo_description": ""
  },
  "created_at": "2026-10-21T10:00:00Z",
  "updated_at": "2026-10-21T12:30:00Z"
}
```

**Правила:**

| Поле | Обязательно | Правило |
|---|---|---|
| `id` | ✅ | `^game-\d{3,}$`, иммутабелен |
| `slug` | ✅ | `^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$`, уникален |
| `title` | ✅ | 1–120 |
| `genre_id` | ✅ | существующий `genre.id` |
| `description` | ✅ | 20–1000 |
| `cover` | ✅ | файл `cover.webp` существует |
| `category_ids` | ✅ | массив `cat.*`, без дубликатов |
| `status` | ✅ | `draft` \| `published` |
| `controls` | ✅ | все обязательные действия движка назначены |
| `sounds` | ✅ | ≤ 10 событий, уникальные |
| `engine_overrides` | ❌ | по схеме движка |
| `legend` | ❌ | по схеме легенды (блок 7) |
| `levels` | ❌ | массив уровней; для публикации ≥ 1 |
| `meta` | ❌ | `seo_title`, `seo_description` |
| `created_at`, `updated_at` | ✅ | ISO 8601 |

**Наследование при создании игры:**

- `controls = genre.default_controls` (полная копия);
- `sounds = genre.default_sounds`;
- `engine_overrides = {}`;
- `legend = {}`;
- `levels = []`.

Если `controls`/`sounds` в игре пусты — при чтении используются дефолты жанра.

**Публикация (`status: published`) требует:**

- `cover` — файл существует;
- `levels.length >= 1`;
- `controls` содержит все обязательные действия движка.

---

## 7. Схема `emoji-bank.json`

```json
[
  {
    "id": "e001",
    "codepoint": "U+E000",
    "name": "Пиксельный рыцарь",
    "category": "персонажи",
    "type": "static",
    "source_format": "png",
    "stored_format": "png",
    "image_path": "media/emoji-bank/e001.png",
    "image_size": {"width": 64, "height": 64},
    "pixel_mode": true,
    "sanitized_hash": null,
    "frames": null,
    "loop_mode": null,
    "created_at": "2026-10-21T18:00:00Z",
    "updated_at": "2026-10-21T18:00:00Z"
  },
  {
    "id": "e020",
    "codepoint": "U+E013",
    "name": "Рыцарь идёт",
    "category": "персонажи",
    "type": "animation",
    "source_format": "animation",
    "stored_format": null,
    "image_path": null,
    "image_size": null,
    "pixel_mode": null,
    "sanitized_hash": null,
    "frames": [
      {"codepoint": "U+E000", "duration_ms": 150},
      {"codepoint": "U+E001", "duration_ms": 150},
      {"codepoint": "U+E002", "duration_ms": 150},
      {"codepoint": "U+E001", "duration_ms": 150}
    ],
    "loop_mode": "loop",
    "created_at": "2026-10-21T19:00:00Z",
    "updated_at": "2026-10-21T19:00:00Z"
  }
]
```

**Правила:**

| Поле | Правило |
|---|---|
| `id` | `^e\d{3,}$`; назначается сервером последовательно |
| `codepoint` | `U+E000`…`U+F8FF`; уникален; иммутабелен; **не переиспользуется** после удаления |
| `name` | 2–80 |
| `category` | `персонажи` \| `оружие` \| `природа` \| `UI` \| `символы` \| `другое` |
| `type` | `static` \| `animation` |
| `source_format` | `png` \| `gif` \| `svg` \| `animation` |
| `stored_format` | `png` (для png/gif) \| `svg` (для svg) \| `null` (для animation) |
| `image_path` | `media/emoji-bank/{id}.{png\|svg}` \| `null` (для animation) |
| `image_size` | `{width, height}` \| `null` (для animation) |
| `pixel_mode` | bool \| `null` (для SVG и animation) |
| `sanitized_hash` | `sha256:...` для SVG \| `null` |
| `frames` | массив `{codepoint, duration_ms}` 2–20 элементов, только для animation |
| `loop_mode` | `loop` \| `once` \| `ping_pong` \| `null` |

**Правила анимации:**

- `frames.length` 2–20.
- `duration_ms` 50–2000 на кадр.
- Сумма `duration_ms` ≤ 10 000.
- Все `frame.codepoint` имеют `type: "static"` и существуют в банке.
- Вложенные анимации запрещены.

**PUA-счётчик (`emoji-bank-counter.json`):**

```json
{"next": 21}
```

`next` = номер последнего назначенного id. При создании нового: `id = e{(next+1):03}`, `codepoint = U+(E000 + next)`. Счётчик **не уменьшается** при удалении.

**Использование:**

- `legend[codepoint]` в `data/games/*.json`;
- `levels[].grid` в `data/games/*.json`;
- `default_legend[codepoint]` в `data/genres.json`;
- `frames[].codepoint` в `data/emoji-bank.json` (для static).

Удаление блокируется, если используется в любом из этих мест.

---

## 8. Схема `ai-models.json`

```json
[
  {
    "id": "chase_with_vision",
    "name": "Преследование при обнаружении",
    "description": "Моб преследует игрока в радиусе видимости; теряет цель при выходе из lose_range",
    "category": "pursuit",
    "supported_engines": ["static-screen", "horizontal-scroll"],
    "reference": "Bomberman (1983, Hudson Soft)",
    "params": [
      {"key": "speed", "type": "number", "min": 0.1, "max": 3.0, "default": 0.5, "unit": "tiles/frame"},
      {"key": "vision_range", "type": "number", "min": 1, "max": 30, "default": 8, "unit": "tiles"},
      {"key": "lose_range", "type": "number", "min": 1, "max": 40, "default": 15, "unit": "tiles"},
      {"key": "turn_on_wall", "type": "boolean", "default": true},
      {"key": "turn_on_edge", "type": "boolean", "default": true},
      {"key": "pathfinding", "type": "enum", "options": ["direct", "greedy", "astar"], "default": "greedy"}
    ],
    "custom": false,
    "created_at": "2026-10-21T10:00:00Z",
    "updated_at": "2026-10-21T10:00:00Z"
  }
]
```

**Правила:**

| Поле | Правило |
|---|---|
| `id` | `^[a-z][a-z0-9_]{1,40}$`, уникален |
| `name` | 2–80 |
| `description` | 10–300 |
| `category` | `movement` \| `pursuit` \| `combat` \| `advanced` \| `boss` |
| `supported_engines` | непустой массив ⊆ {`static-screen`, `vertical-scroll`, `horizontal-scroll`} |
| `reference` | 0–120, опционально |
| `params[]` | ≤ 20 элементов; каждый: `key`, `type` (`number`/`boolean`/`enum`/`string`), `min`/`max`/`options`/`default` |
| `custom` | `false` для встроенных (read-only), `true` для кастомных |

**Полный перечень 50 моделей** — см. блок 5.

---

## 9. Схема `movement-patterns.json`

```json
[
  {
    "id": "sine_horizontal",
    "name": "Горизонтальная синусоида",
    "description": "Полёт вниз с горизонтальными колебаниями",
    "category": "oscillating",
    "supported_engines": ["vertical-scroll", "horizontal-scroll"],
    "reference": "Gradius (1985, Konami)",
    "params": [
      {"key": "speed", "type": "number", "min": 0.1, "max": 10.0, "default": 2.0},
      {"key": "amplitude", "type": "number", "min": 0.5, "max": 10.0, "default": 3.0},
      {"key": "frequency_hz", "type": "number", "min": 0.05, "max": 3.0, "default": 0.5},
      {"key": "phase", "type": "number", "min": 0, "max": 6.28, "default": 0}
    ],
    "trajectory_hint": "x(t) = base_x + amplitude·sin(2π·f·t + φ); y(t) = base_y + speed·t",
    "on_finish": "despawn",
    "custom": false,
    "created_at": "2026-10-21T10:00:00Z",
    "updated_at": "2026-10-21T10:00:00Z"
  }
]
```

**Дополнительно к AI-моделям:**

| Поле | Правило |
|---|---|
| `category` | `linear` \| `oscillating` \| `curved` \| `homing` \| `composite` \| `bit8-classic` |
| `trajectory_hint` | 0–200, опционально |
| `on_finish` | `despawn` \| `straight_down` \| `loop` |

**Полный перечень 50 паттернов** — см. блок 5.

---

## 10. Схема `stats.json` (опционально)

```json
{
  "game-001": {
    "views": 150,
    "plays": 80,
    "last_view_at": "2026-10-21T20:00:00Z",
    "last_play_at": "2026-10-21T20:05:00Z"
  }
}
```

Пишется только при `config.analytics_enabled = true`.

---

## 11. `audit.log` — формат

JSON Lines. Одна строка — одна запись:

```json
{"ts":"2026-10-21T10:00:00Z","user":"admin","action":"login","ip":"127.0.0.1","ua":"Mozilla/5.0..."}
{"ts":"2026-10-21T10:05:00Z","user":"admin","action":"genre.create","target":"genre-platformer","details":{"engine":"horizontal-scroll"}}
{"ts":"2026-10-21T10:10:00Z","user":"admin","action":"game.bulk_publish","details":{"count":5,"ids":["game-001","game-002"]}}
{"ts":"2026-10-21T10:15:00Z","user":null,"action":"login_fail","details":{"username":"admin","ip":"1.2.3.4"}}
```

**Общие поля:**

- `ts` — ISO 8601.
- `user` — `username` или `null` (для анонимных).
- `action` — строка (см. перечень в блоках 3–11).
- `target` — опционально (id сущности).
- `details` — опционально (минимальные поля).
- `ip` — `REMOTE_ADDR` или `X-Forwarded-For` (если `trusted_proxies`).
- `ua` — только для `login`/`logout`/`login_fail`.

**Ротация:** по достижении 10 MB — переименование в `audit-YYYYMMDD.log`, новый `audit.log`. Опционально — `config.audit_max_size_mb`.

---

## 12. `throttle.json` — защита от брутфорса

```json
{
  "sha1(ip)": {
    "fail_count": 3,
    "first_fail_at": "2026-10-21T10:00:00Z",
    "last_fail_at": "2026-10-21T10:02:00Z"
  }
}
```

Счётчик сбрасывается через 10 минут после последнего провала или при успешном входе.

---

## 13. Rate-limiting — файлы `data/rate-limit/`

```
data/rate-limit/
  {sha1(ip)}.json      # {"window_start": 1697900000, "count": 45}
```

Файлы старше 1 часа удаляются лениво при записи.

---

## 14. Резюме схем данных

| Файл | Назначение | Размер (типично) |
|---|---|---|
| `users.json` | Администраторы | < 1 KB |
| `genres.json` | 5 жанров с полными конфигами | 30–50 KB |
| `categories.json` | Дерево категорий | < 5 KB |
| `games/{id}.json` | Одна игра | 10–500 KB (с уровнями) |
| `emoji-bank.json` | Банк эмодзи | 10–100 KB |
| `emoji-bank-counter.json` | Счётчик PUA | < 50 B |
| `ai-models.json` | 50 AI-моделей | 40–60 KB |
| `movement-patterns.json` | 50 паттернов | 40–60 KB |
| `throttle.json` | Счётчики брутфорса | < 5 KB |
| `stats.json` | Аналитика | < 100 KB |
| `audit.log` | Журнал | растёт, ротация 10 MB |
| `rate-limit/*.json` | Rate-limiting | < 5 KB |

Все JSON — UTF-8, отформатированы (`JSON_PRETTY_PRINT`), сериализуются с `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`.

---

**Конец блока 2 из 12.**

Следующий блок — **Безопасность и аутентификация** (аутентификация, CSRF, изоляция каталогов, валидация путей, rate-limiting, безопасность SVG, безопасность загрузки медиа).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 3. Безопасность и аутентификация

## 1. Модель угроз (краткая)

**Активы:**
- учётные записи администраторов;
- содержимое `data/` (жанры, игры, эмодзи, AI-модели, паттерны);
- медиафайлы в `public/media/`;
- целостность CMS и игр.

**Потенциальные атакующие:**
- неавторизованный внешний пользователь (интернет);
- администратор с ограниченными правами (сейчас одна роль `admin`, но модель учитывает будущее);
- скомпрометированный браузер администратора (XSS, CSRF).

**Ключевые риски:**
- несанкционированный доступ к админке;
- XSS через пользовательский контент (названия, описания, SVG);
- CSRF на изменяющих запросах;
- XXE/SSRF/path traversal через загрузку медиа;
- DoS через публичные эндпоинты;
- утечка `data/` и `config/`.

**Принятые ограничения:**
- при компрометации админки CMS считается скомпрометированной (защита второго контура — вне объёма);
- защита от целевых APT-атак не входит в объём (базовая защита от OWASP Top 10 — входит);
- WAF/CDN-уровень — вне объёма (можно добавить на уровне инфраструктуры).

---

## 2. Аутентификация

### 2.1. Хранение паролей

- Пароли хранятся **только** в виде `password_hash($password, PASSWORD_BCRYPT, ['cost' => 12])`.
- Открытых паролей нет нигде: ни в `users.json`, ни в логах, ни в `audit.log`, ни в `secrets.php`.
- Проверка — `password_verify()` (timing-safe).
- Требования к паролю при создании/смене:
  - длина ≥ 10 символов;
  - рекомендуется (мягкая валидация) — наличие букв и цифр;
  - запрещены: пустой пароль, пароль из белого списка (`123456`, `password`, `admin`, `qwerty` — фиксированный список).

### 2.2. Вход

**`POST /admin/login`:**

Поля: `username`, `password`, `_csrf`.

Процесс:
1. Проверка CSRF-токена (см. §3).
2. Проверка throttle (см. §2.5).
3. Поиск пользователя по `username` в `users.json`.
4. `password_verify($password, $user['password_hash'])`.
5. При успехе:
   - `session_regenerate_id(true)`;
   - `$_SESSION['user'] = ['id' => ..., 'username' => ..., 'role' => ...]`;
   - `$_SESSION['last_activity'] = time()`;
   - обновление `last_login_at` в `users.json` (через `FileStore::mutate`);
   - сброс throttle-счётчика для IP;
   - `audit.log` → `login`.
6. При неудаче:
   - инкремент throttle-счётчика;
   - `audit.log` → `login_fail` (с `username`, `ip`, `ua`);
   - **общее сообщение** «Неверное имя или пароль» (без указания, что именно не так).

**Требования:**
- Форма входа не содержит ссылок на «забыли пароль» (нет email-механизма; восстановление — CLI).
- При `login_fail` — задержка `usleep` для уменьшения скорости перебора (в дополнение к throttle).

### 2.3. Сессии

**Параметры cookie:**

| Параметр | Значение |
|---|---|
| `name` | `cms_session` |
| `HttpOnly` | `true` |
| `SameSite` | `Lax` |
| `Secure` | `true` при HTTPS, `false` для локальной разработки (флаг в `config.php`) |
| `Path` | `/` |
| `Domain` | не задан |

**Параметры сессии:**

- `session.use_strict_mode = 1`;
- `session.use_only_cookies = 1`;
- `session.cookie_httponly = 1`;
- `session.gc_maxlifetime = 3600`;
- хранение — файлы PHP по умолчанию (`session.save_path` — вне web-root).

**Таймаут неактивности:** 60 минут (`config.session_timeout`). При превышении — `session_destroy()`, редирект на `/admin/login`.

**Проверка на каждом запросе к `/admin/*`:**

1. Наличие `$_SESSION['user']`.
2. `time() - $_SESSION['last_activity'] < config.session_timeout`.
3. `$_SESSION['user_agent_hash'] === hash('sha256', $_SERVER['HTTP_USER_AGENT'])` (защита от кражи cookie при смене UA).
4. При провале любой проверки — `session_destroy()`, `302` на `/admin/login`.

**Обновление `last_activity`:** на каждом успешном запросе к `/admin/*`.

### 2.4. Выход

**`POST /admin/logout`:**

1. Проверка CSRF.
2. `audit.log` → `logout`.
3. `$_SESSION = []`; `session_destroy()`; удаление cookie.
4. `302` на `/admin/login`.

GET-запрос на `/admin/logout` не изменяет состояние (возвращает форму с кнопкой).

### 2.5. Защита от брутфорса

**`data/throttle.json`:**

```json
{
  "sha1(ip)": {
    "fail_count": 3,
    "first_fail_at": "2026-10-21T10:00:00Z",
    "last_fail_at": "2026-10-21T10:02:00Z"
  }
}
```

**Алгоритм:**

1. При `POST /admin/login` — чтение записи по `sha1(REMOTE_ADDR)`.
2. Если `fail_count >= 5` **и** `now - first_fail_at < 10 минут` — отклонение с `429 Too Many Requests` и заголовком `Retry-After`.
3. При неудачном входе — инкремент `fail_count`, обновление `last_fail_at`; при `fail_count == 1` — `first_fail_at = now`.
4. При успешном входе — удаление записи для IP.
5. Ленивая чистка: записи старше 1 часа удаляются при следующей записи.

**Дополнительно:**

- `usleep(random_int(200_000, 500_000))` при `login_fail` — равномерная задержка против timing-атак.
- Не раскрывается, что именно неверно.

### 2.6. Управление пользователями

**CLI `cli/create-admin.php`:**

```bash
php cli/create-admin.php --username=admin --password=secret
```

- Идемпотентна: повторный запуск с тем же `username` завершается ошибкой и кодом выхода 1.
- Валидация пароля по §2.1.
- Запись в `users.json` через `FileStore::write` (атомарно).
- `audit.log` → `user.create` (без указания пароля).

**Смена пароля (в админке, `/admin/users`):**

- Форма: `old_password`, `new_password`, `new_password_confirm`, `_csrf`.
- Проверка `password_verify(old_password)`, валидация `new_password` по §2.1, совпадение `new_password_confirm`.
- `session_regenerate_id(true)` после смены.
- `audit.log` → `user.password_change`.

**Восстановление пароля:**

- **Нет** механизма «забыли пароль» через email (нет email-инфраструктуры).
- Восстановление — только через CLI `create-admin.php` с новым паролем или ручную правку `users.json` владельцем сервера.

---

## 3. CSRF

### 3.1. Токен

- Генерируется в сессии при первом запросе к `/admin/*`: `bin2hex(random_bytes(32))`.
- Хранится в `$_SESSION['csrf_token']`.
- **Не ротируется** на каждый запрос (стабилен в течение сессии); ротация — только при логине.

### 3.2. Передача

Все POST-формы админки содержат hidden-поле:

```html
<input type="hidden" name="_csrf" value="<?= htmlspecialchars($csrfToken) ?>">
```

AJAX-запросы передают токен в заголовке `X-CSRF-Token`.

### 3.3. Проверка

На **каждом POST-запросе** к `/admin/*` (кроме `/admin/login` — там CSRF проверяется от анонимной сессии):

1. Извлечение токена из `$_POST['_csrf']` или `$_SERVER['HTTP_X_CSRF_TOKEN']`.
2. `hash_equals($_SESSION['csrf_token'], $token)`.
3. При провале — `403 Forbidden`, лог в `audit.log` → `csrf_fail` (с `ip`, `url`).

**Исключения:** нет. Все POST — с CSRF.

### 3.4. Дополнительно

- GET-запросы **не изменяют** состояние.
- Разрушительные действия (удаление, массовые операции) — только POST.
- Двойное подтверждение для удаления: CSRF + модалка с вводом ключевого значения.

---

## 4. Изоляция каталогов

### 4.1. `/data/` и `/config/`

**Требование:** физически **вне web-root** (`public/`).

Если развёртывание вынуждает держать их внутри web-root — **обязательно** запрет на уровне веб-сервера.

**Apache (`.htaccess` в корне):**

```apache
RedirectMatch 404 ^/(data|config|src|admin|cli|tests|docs)/
```

**Nginx:**

```nginx
location ~ ^/(data|config|src|admin|cli|tests|docs)/ {
    deny all;
}
```

**Проверка при приёмке:** `GET /data/users.json`, `GET /config/secrets.php`, `GET /data/games/game-001.json` — должны вернуть `403` или `404`.

### 4.2. `/public/media/`

Разрешён доступ, но **исполнение PHP запрещено**.

**Apache (`public/media/.htaccess`):**

```apache
php_flag engine off
RemoveHandler .php .phtml .sh .html .htm
AddType text/plain .php .phtml .sh .html .htm
```

**Nginx:**

```nginx
location /media/ {
    location ~ \.(php|phtml|sh|html|htm)$ { deny all; }
    add_header X-Content-Type-Options "nosniff" always;
}
```

### 4.3. `/public/media/emoji-bank/`

Дополнительно к §4.2 — заголовки безопасности для SVG:

**Apache (`public/media/emoji-bank/.htaccess`):**

```apache
<FilesMatch "\.svg$">
    ForceType image/svg+xml
    Header always set Content-Security-Policy "default-src 'none'; img-src data:;"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Content-Disposition "inline"
    Header always set X-Frame-Options "DENY"
</FilesMatch>
```

**Nginx:**

```nginx
location /media/emoji-bank/ {
    types { image/svg+xml svg; }
    add_header Content-Security-Policy "default-src 'none'; img-src data:;" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    location ~ \.(php|phtml|sh|html|htm)$ { deny all; }
}
```

**Рекомендация (вне объёма, но описано в SECURITY.md):** для production — вынести `/media/emoji-bank/` на отдельный поддомен (`emoji.example.com`) для изоляции cookie.

### 4.4. `config/secrets.php`

- Вне git (`.gitignore`).
- Права `0600` (только владелец).
- Владелец — пользователь PHP-FPM.
- Шаблон — `config/secrets.example.php` с пустыми значениями.

---

## 5. Валидация путей

### 5.1. Валидация в `FileStore`

Каждый относительный путь, переданный в `FileStore::read`/`write`/`delete`/`mutate`:

1. Соответствует `^[a-zA-Z0-9._/-]+$`.
2. Не содержит `..` (подстрока).
3. Не начинается с `/`.
4. Не содержит `\0`.
5. `realpath($rootDir . '/' . $path)` начинается с `realpath($rootDir) . '/'`.

При нарушении — `StorageException`.

### 5.2. Валидация ID и slug в URL

| Параметр | Regex |
|---|---|
| `game-id` | `^game-\d{3,}$` |
| `level-id` | `^level-\d{3,}$` |
| `emoji-id` | `^e\d{3,}$` |
| `genre-id` | `^genre-[a-z0-9-]+$` |
| `cat-id` | `^cat-[a-z0-9-]+$` |
| `user-id` | `^user-\d{3,}$` |
| `slug` (публичный) | `^[a-z0-9][a-z0-9-]{0,78}[a-z0-9]$` |
| `ai-model-id` | `^[a-z][a-z0-9_]{1,40}$` |
| `pattern-id` | `^[a-z][a-z0-9_]{1,40}$` |

При несоответствии — `404`.

### 5.3. Валидация slug при генерации

- Транслитерация кириллицы → латиница.
- `lowercase`, замена пробелов и всего, что не `[a-z0-9-]`, на `-`.
- Коллапс повторных `-`, trim по краям.
- Если пусто после обработки — ошибка «Не удалось сгенерировать slug».
- Если занято — суффикс `-2`, `-3`, …

---

## 6. Rate-limiting

### 6.1. Лимиты

| Эндпоинт | Лимит | Окно |
|---|---|---|
| `POST /admin/login` | 5 неудач | 10 минут (через `throttle.json`, §2.5) |
| `GET /play/*` | 60 | 60 сек на IP |
| `GET /api/emoji-bank.json` | 120 | 60 сек на IP |
| `GET /games`, `/genres`, `/categories`, `/emoji` | 120 | 60 сек на IP |
| `GET /` (лендинг) | 120 | 60 сек на IP |

### 6.2. Реализация

**`src/RateLimit/Limiter.php`:**

```php
public function check(string $key, int $limit, int $windowSeconds): bool;
```

- Ключ — `sha1($endpoint . ':' . $ip)`.
- Файл — `data/rate-limit/{key}.json`.
- Формат: `{"window_start": 1697900000, "count": 45}`.
- При `now - window_start > windowSeconds` — сброс счётчика, `window_start = now`.
- Инкремент `count`. Если `count > limit` — `false`.
- Ленивая чистка: при записи удаляются файлы старше 1 часа.

### 6.3. Ответ при превышении

```
HTTP/1.1 429 Too Many Requests
Retry-After: <секунд до конца окна>
Content-Type: text/plain

Too many requests. Please try again later.
```

- `audit.log` → `public.rate_limit` (опционально, при `config.audit_public_events = true`).

### 6.4. IP-адрес

- При отсутствии прокси — `$_SERVER['REMOTE_ADDR']`.
- При наличии прокси (из `config.trusted_proxies`) — `X-Forwarded-For` (первый недоверенный).
- При некорректном `X-Forwarded-For` — fallback на `REMOTE_ADDR`.

---

## 7. Безопасность SVG

### 7.1. Санитизация до геометрии

`src/Svg/GeometrySvgSanitizer.php` — обязательный этап загрузки SVG.

**Разрешённые элементы:** `svg`, `g`, `defs`, `linearGradient`, `radialGradient`, `stop`, `rect`, `circle`, `ellipse`, `line`, `polyline`, `polygon`, `path`.

**Разрешённые атрибуты:**

- `svg`: `viewBox`, `width`, `height`, `xmlns`.
- Геометрия: `x`, `y`, `width`, `height`, `rx`, `ry`, `cx`, `cy`, `r`, `x1`, `y1`, `x2`, `y2`, `points`, `d`.
- Трансформация: `transform` (только `matrix`/`translate`/`scale`/`rotate`/`skewX`/`skewY`).
- Цвета: `fill`, `stroke`, `stop-color` — `none`|`#RGB`|`#RRGGBB`|`#RGBA`|`#RRGGBBAA`|`rgb()`|`rgba()`|`hsl()`|`hsla()`|именованные CSS|`url(#id)`, где `id` — существующий `linearGradient`/`radialGradient` **в этом же файле**.
- Прозрачность: `opacity`, `fill-opacity`, `stroke-opacity`, `stop-opacity` — 0…1.
- Обводка: `stroke-width`, `stroke-linecap` (`butt`/`round`/`square`), `stroke-linejoin` (`miter`/`round`/`bevel`), `stroke-dasharray`, `stroke-dashoffset`, `fill-rule` (`nonzero`/`evenodd`), `clip-rule`.
- Градиенты: `x1`,`y1`,`x2`,`y2`,`cx`,`cy`,`r`,`fx`,`fy`,`gradientUnits` (`objectBoundingBox`|`userSpaceOnUse`), `gradientTransform`, `spreadMethod` (`pad`|`reflect`|`repeat`).
- `stop`: `offset`, `stop-color`, `stop-opacity`.
- `id` — `^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$`.

**Запрещено (удаляется):**

- элементы: `text`, `tspan`, `image`, `use`, `foreignObject`, `script`, `style`, `animate*`, `set`, `filter`, `clipPath`, `mask`, `pattern`, `marker`, `symbol`, `iframe`, `audio`, `video`;
- атрибуты: `on*`, `xlink:*`, `href`, `class`, `src`, `xml:base`;
- значения: `currentColor`, `url(...)` на внешние ресурсы, `expression(...)`, `javascript:`, `@import`.

**Защита от XXE:**

- `libxml_disable_entity_loader(true)` (для PHP < 8.0; в 8.0+ — не требуется, но оставить для совместимости).
- `LIBXML_NONET`.
- Явная проверка строки на `<!DOCTYPE` и `<!ENTITY` — при обнаружении отклонение.

**Проверка связности `url(#id)`:**

- Собираются все `id` градиентов.
- Все `url(#id)` в `fill`/`stroke`/`stop-color` сверяются с этим списком.
- При несовпадении — атрибут заменяется на `none`.

**Проверка «есть фигура»:**

- После санитизации должна остаться хотя бы одна фигура (`path`/`rect`/`circle`/`ellipse`/`polygon`/`polyline`/`line`).
- Иначе — отказ в загрузке.

**Сериализация:**

- `DOMDocument::saveXML($root)` без XML declaration, без комментариев, без текстовых узлов.
- `sanitized_hash = sha256(содержимое)`.

### 7.2. Изоляция при отдаче

См. §4.3.

### 7.3. Рендер

- Все SVG рендерятся через `<img>` или `new Image()` в JS.
- **Запрещено:** инлайн-вставка SVG в DOM, `innerHTML`, `insertAdjacentHTML`, `document.write`.
- В `<img>`-контексте браузер не исполняет скрипты и не загружает внешние ресурсы.

### 7.4. Валидация при импорте ZIP

- Каждый SVG в архиве повторно санитизируется (не доверяем `sanitized_hash` из архива).

---

## 8. Безопасность загрузки медиа

### 8.1. Обложки игр (PNG, JPEG, WebP, GIF)

| Этап | Требование |
|---|---|
| Приём | `multipart/form-data`, поле `cover`; максимум 5 MB |
| MIME | `finfo_file()` ∈ `{image/jpeg, image/png, image/webp, image/gif}` |
| Размеры | `getimagesize()` 200×150 … 4000×3000 |
| Защита от decompression bomb | `getimagesize()` до создания ресурса + лимит 4000×3000 |
| Ре-энкод | Обязателен; оригинал не сохраняется |
| Результат | WebP q=80, 640×480 letterbox (фон `#000022`) |
| Перезапись | `cover.webp` перезаписывается, старая версия → `cover-prev.webp` |
| Транзакция | Если запись файла не удалась — JSON не обновляется |

**Отказ при:**

- MIME вне списка;
- `getimagesize` вернул `false`;
- размеры вне диапазона;
- `imagecreatefrom*` вернул `false`;
- запись `imagewebp` вернула `false`.

### 8.2. Банк эмодзи: PNG

| Этап | Требование |
|---|---|
| Приём | `multipart/form-data`; максимум 200 KB |
| MIME | `finfo_file()` ∈ `{image/png}` |
| Размеры | 8×8 … 256×256 |
| Обработка | `pixel_mode`: downscale до 16×16 nearest → upscale до 64×64 nearest; иначе letterbox 64×64 |
| Ре-энкод | Обязателен; оригинал не сохраняется |
| Результат | `media/emoji-bank/{id}.png` 64×64 |

### 8.3. Банк эмодзи: GIF

- Аналогично PNG.
- `imagecreatefromgif()` возвращает первый кадр.
- Ре-энкод в PNG.
- Анимированный GIF не сохраняется (только первый кадр).

### 8.4. Банк эмодзи: SVG

| Этап | Требование |
|---|---|
| Приём | Максимум 200 KB |
| MIME | `finfo_file()` ∈ `{image/svg+xml, text/xml, application/xml}` |
| Санитизация | `GeometrySvgSanitizer::sanitize()` |
| Проверка | Есть хотя бы одна фигура; `viewBox`/`width`/`height` ≤ 512 |
| Результат | `media/emoji-bank/{id}.svg` (не растеризуется) |
| Хэш | `sanitized_hash = sha256(содержимое)` |
| Оригинал | Не сохраняется |

### 8.5. Анимации (из кадров)

- Кадры — существующие `static`-эмодзи банка.
- Валидация:
  - 2–20 кадров;
  - `duration_ms` 50–2000;
  - сумма ≤ 10 000;
  - все `codepoint` имеют `type: "static"` и существуют;
  - запрет вложенных анимаций.
- Файлов не создаётся — анимация ссылается на кадры.

### 8.6. ZIP (экспорт/импорт)

**Экспорт:**

- Собирается на сервере из существующих файлов.
- Не принимает пользовательских файлов.

**Импорт:**

| Этап | Требование |
|---|---|
| Приём | Максимум 20 MB |
| MIME | `finfo_file()` ∈ `{application/zip, application/x-zip-compressed}` |
| Структура | `emoji-bank.json`, `emoji-bank-counter.json`, `files/*` |
| **Zip-slip** | Все пути в архиве проверяются: `files/{id}.{png\|svg}`; пути вне этого шаблона → отказ |
| Валидация JSON | Схема, уникальность `id`/`codepoint` |
| Валидация файлов | Каждый PNG/GIF → ре-энкод через GD; каждый SVG → повторная санитизация |
| Атомарность | Сначала все проверки, затем запись; при ошибке в любой записи — откат (все новые файлы удаляются, JSON не изменяется) |
| Лимит | ≤ 6400 записей (весь PUA-диапазон) |

**Защита от zip-bomb:** лимит распакованного размера — 100 MB; `ZipArchive` проверяет `getStream` до чтения.

---

## 9. XSS

### 9.1. Экранирование при выводе

- Все пользовательские данные, выводимые в HTML, экранируются через `htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8')`.
- Касается: названий, описаний, slug, имён пользователей, значений `emoji` в легенде, названий уровней, `meta.seo_*`.

### 9.2. Встраивание JSON в HTML

При SSR-странице `/play/*` bootstrap-JSON встраивается через:

```html
<script type="application/json" id="bootstrap">{json}</script>
```

Чтение — `JSON.parse(document.getElementById('bootstrap').textContent)`.

**Защита от закрытия тега:** при сериализации `</script>` заменяется на `<\/script>`; `<` заменяется на `\u003c` для строковых значений (через `json_encode` с `JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT`).

### 9.3. Запрет небезопасных методов

- **Не используется** `innerHTML` для пользовательских данных.
- **Не используется** `document.write`.
- **Не используется** `eval`, `new Function`, `setTimeout(string)`.
- `trajectory_hint` (справочная формула) — выводится как текст, не исполняется.

### 9.4. CSP (Content Security Policy)

Заголовок на всех публичных и административных страницах:

```
Content-Security-Policy: default-src 'self';
  script-src 'self';
  style-src 'self' 'unsafe-inline';
  img-src 'self' data:;
  font-src 'self';
  connect-src 'self';
  media-src 'self';
  object-src 'none';
  frame-ancestors 'none';
  base-uri 'self';
  form-action 'self';
```

`'unsafe-inline'` для `style-src` — из-за инлайн-стилей в редакторе уровней; допустимо, так как инлайн-стили контролируются разработчиком.

Для `/media/emoji-bank/*.svg` — отдельная CSP (см. §4.3).

---

## 10. Прочие заголовки безопасности

| Заголовок | Значение | Где |
|---|---|---|
| `X-Content-Type-Options` | `nosniff` | Все ответы |
| `X-Frame-Options` | `DENY` | Все HTML-страницы (запрет фрейминга) |
| `Referrer-Policy` | `same-origin` | Все HTML-страницы |
| `Permissions-Policy` | `camera=(), microphone=(), geolocation=(), interest-cohort=()` | Все HTML-страницы |
| `Strict-Transport-Security` | `max-age=31536000; includeSubDomains` | Только при HTTPS |
| `Cache-Control` | `no-store` для админки; `public, max-age=3600` для статики и `/api/emoji-bank.json` | Соответственно |

---

## 11. Аудит

### 11.1. `audit.log`

Формат JSON Lines. **Всегда** фиксируются:

- вход/выход/неудачный вход;
- создание/изменение/удаление сущностей (жанр, категория, игра, уровень, эмодзи, AI-модель, паттерн);
- массовые операции;
- смена `engine` у жанра;
- изменение `controls`/`sounds`/`engine_overrides`;
- копирование настроек между играми;
- CSRF-провал;
- rate-limit (опционально, при `audit_public_events = true`);
- 404/500 на публичных страницах (опционально, при `audit_public_events = true`).

**Не фиксируются:**

- пароли (ни в каком виде);
- содержимое `secrets.php`;
- персональные данные игроков (их нет).

### 11.2. Ротация

По достижении 10 MB — переименование в `audit-YYYYMMDD-HHMMSS.log`, создание нового. Хранение — `config.audit_rotation_keep` (по умолчанию 14 файлов). Старые удаляются.

### 11.3. Просмотр

- `/admin/log` — последние 200 записей с фильтрами по `action` и `user`.
- Полный `audit.log` доступен только владельцу сервера (через SSH).

---

## 12. Проверка безопасности (чек-лист приёмки)

### 12.1. OWASP Top 10 (2021)

| # | Категория | Проверка | Ожидаемый результат |
|---|---|---|---|
| A01 | Broken Access Control | `/admin/*` требует сессии; `/data/` закрыт; `draft`-игры 404 | ✅ |
| A02 | Cryptographic Failures | bcrypt; `Secure` cookie при HTTPS; секреты вне git | ✅ |
| A03 | Injection | XSS — `htmlspecialchars`; SVG — санитайзер; SQL — нет | ✅ |
| A04 | Insecure Design | Модель угроз; rate-limiting; подтверждения | ✅ |
| A05 | Security Misconfiguration | `php_flag engine off`; CSP; `nosniff`; `X-Frame-Options`; `display_errors=0` в prod | ✅ |
| A06 | Vulnerable Components | Проверка версии PHP; `composer audit` (если Composer) | ✅ |
| A07 | Identification and Authentication Failures | bcrypt; throttle; `session_regenerate_id` | ✅ |
| A08 | Software and Data Integrity | ZIP валидируется; SVG повторно санитизируется | ✅ |
| A09 | Security Logging and Monitoring | `audit.log`; `backup.log`; `play.log` (опц.) | ✅ |
| A10 | SSRF | SVG без внешних URL; `FileStore` без сетевых запросов | ✅ |

### 12.2. Специфические проверки проекта

| Проверка | Метод | Ожидание |
|---|---|---|
| XXE в SVG | SVG с `<!DOCTYPE svg [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>` | Отклонён |
| XSS в названии игры | Название `<script>alert(1)</script>` | Экранировано при выводе |
| Path traversal в `{slug}` | `/games/../../etc/passwd` | 404 |
| Path traversal в `FileStore` | `FileStore::read('../../etc/passwd')` | `StorageException` |
| CSRF | POST без `_csrf` | 403 |
| Session fixation | Логин, сравнение ID до/после | ID изменён |
| Session hijack | Подмена `User-Agent` | Сессия разорвана |
| Брутфорс | 6 неверных паролей подряд | 429 на 6-й |
| Rate-limit `/play/*` | 61 запрос за 60 сек | 429 с `Retry-After` |
| Раскрытие `/data/users.json` | GET | 403 или 404 |
| Раскрытие `/config/secrets.php` | GET | 403 или 404 |
| SVG-полиглот | SVG с HTML-содержимым внутри | Санитизирован |
| SVG с `<script>` | | Элемент удалён |
| SVG с `onload` | | Атрибут удалён |
| SVG с `url(#missing)` | | `fill` → `none` |
| SVG с `url(http://evil.com/x)` | | Атрибут удалён |
| SVG без фигур | Только `<text>` | Отказ в загрузке |
| ZIP-slip | ZIP с `../evil.php` | Отклонён |
| ZIP с SVG | SVG повторно санитизирован | ✅ |
| GIF-бомба | GIF 65535×65535 | Отклонён (`getimagesize`) |
| PNG-полиглот | PNG с PHP-кодом в метаданных | Ре-энкод через GD, PHP-код не сохраняется |
| Подмена MIME | `.png` с содержимым HTML | Отклонён (`finfo_file`) |

### 12.3. Специфические конфигурационные проверки

| Проверка | Ожидание |
|---|---|
| `display_errors` в production | `Off` |
| `expose_php` | `Off` |
| `session.cookie_httponly` | `On` |
| `session.use_strict_mode` | `On` |
| `allow_url_fopen` | `Off` (рекомендация) |
| `allow_url_include` | `Off` |
| Права `/data/` | `0700` (только владелец) |
| Права `/config/secrets.php` | `0600` |
| Права `/public/media/` | `0755` |
| Владелец `data/`/`config/` | PHP-FPM user |

---

## 13. Управление инцидентами

### 13.1. При обнаружении уязвимости

1. Уведомление по контакту из `SECURITY.md` (владелец сервера).
2. Патч в течение 24–72 часов в зависимости от критичности.
3. Запись в `CHANGELOG.md` раздел `Fixed`.
4. `audit.log` → `security.incident` (без публичных деталей).

### 13.2. При компрометации админки

1. Немедленно: `cli/create-admin.php` с новым паролем; блокировка старых сессий (удаление `session.save_path`).
2. Проверка `audit.log` на предмет несанкционированных действий.
3. Восстановление из бэкапа при необходимости.
4. Ротация `session_secret` в `config/secrets.php`.
5. Анализ вектора атаки и патч.

### 13.3. При компрометации данных

1. Уведомление владельца.
2. Восстановление из ближайшего чистого бэкапа.
3. Анализ целостности JSON (валидация схем).
4. Ротация всех паролей.

---

**Конец блока 3 из 12.**

Следующий блок — **Админка: общее, жанры, категории** (layout, дашборд, флеш-сообщения, модалки, CRUD жанров и категорий, seed-команды, валидация, аудит).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 4. Админка: общее, жанры, категории

## 1. Общее устройство админки

### 1.1. Доступ

- Все URL `/admin/*` требуют активной сессии (см. блок 3, §2.3).
- При отсутствии сессии — `302` на `/admin/login`.
- При истёкшей сессии — `302` на `/admin/login` с flash «Сессия истекла. Войдите заново».

### 1.2. Layout

Единый шаблон `layout.php` для всех страниц админки:

```
┌──────────────────────────────────────────────────────────────┐
│ TOPBAR                                                        │
│  [логотип «8-bit CMS»]           [user] admin ▼  [Выйти]     │
├────────────┬─────────────────────────────────────────────────┤
│ SIDEBAR    │ CONTENT                                          │
│            │                                                  │
│ Dashboard  │  ┌─ Page header ─────────────────────────────┐  │
│ Жанры      │  │ Заголовок страницы          [кнопки]      │  │
│ Категории  │  └───────────────────────────────────────────┘  │
│ Игры       │                                                  │
│ AI-модели  │  ┌─ Flash messages ──────────────────────────┐  │
│ Паттерны   │  │ ✅ Сохранено / ❌ Ошибка                  │  │
│ Банк       │  └───────────────────────────────────────────┘  │
│ Пользователи                                                  │
│ Журнал     │  Основной контент страницы                       │
│            │                                                  │
└────────────┴─────────────────────────────────────────────────┘
```

**Sidebar** — фиксированный список пунктов:

| Пункт | URL |
|---|---|
| Dashboard | `/admin` |
| Жанры | `/admin/genres` |
| Категории | `/admin/categories` |
| Игры | `/admin/games` |
| AI-модели | `/admin/ai-models` |
| Паттерны | `/admin/patterns` |
| Банк эмодзи | `/admin/emoji-bank` |
| Пользователи | `/admin/users` |
| Журнал | `/admin/log` |

Активный пункт подсвечен.

**Topbar:**

- Логотип-ссылка на `/admin`.
- Имя текущего пользователя с выпадающим меню: «Сменить пароль», «Выйти».
- Версия CMS в футере сайдбара.

**Контент:** ширина — до 1280 px, центрирование, отступы 24 px.

### 1.3. Флеш-сообщения

Механизм: `$_SESSION['flash'][]` — массив `{type, message}`. Читается и очищается при следующем рендере.

Типы:

- `success` — зелёное, ✅.
- `error` — красное, ❌.
- `warning` — жёлтое, ⚠.
- `info` — синее, ℹ.

Автоскрытие через 8 секунд (JS), закрытие по клику.

### 1.4. Модалки подтверждения

Для разрушительных операций (удаление сущности, смена `engine` жанра, массовые операции). Модалка содержит:

- заголовок;
- описание последствий;
- **поле для ввода ключевого значения** (slug, название, слово «УДАЛИТЬ») — защита от случайного клика;
- кнопки «Отмена» / «Подтвердить» (красная).

Пока ключевое значение не введено — кнопка «Подтвердить» disabled.

### 1.5. Страница 404 админки

Неизвестный `/admin/*` — страница 404 в стиле админки с ссылкой на `/admin`.

### 1.6. Обработка ошибок

- Ошибки валидации — массив `['field' => 'message']`, отображаются рядом с полями.
- Ошибка сохранения (файловая система) — flash `error` «Не удалось сохранить: <причина>».
- Непойманное исключение — страница 500 в стиле админки (без stack trace в production; stack trace — при `config.debug = true`).

---

## 2. Дашборд (`/admin`)

### 2.1. Содержимое

```
┌─ Счётчики ─────────────────────────────────────────────────────┐
│  Жанров: 5    Категорий: 12    Игр: 87    Уровней: 350         │
│  AI-моделей: 50    Паттернов: 50    Эмодзи в банке: 42         │
│  Пользователей: 1                                              │
└────────────────────────────────────────────────────────────────┘

┌─ Топ игр по просмотрам (аналитика, если включена) ─────────────┐
│  1. Золото Бунгелингов          150 просмотров                 │
│  2. Речной рейд                 120                            │
│  ...                                                           │
└────────────────────────────────────────────────────────────────┘

┌─ Топ игр по запускам ──────────────────────────────────────────┐
│  ...                                                           │
└────────────────────────────────────────────────────────────────┘

┌─ Последние действия ───────────────────────────────────────────┐
│  10:15  admin   game.create    game-087                        │
│  10:10  admin   genre.update   genre-platformer                │
│  ...                                                           │
└────────────────────────────────────────────────────────────────┘
```

**Счётчики:**

- «Жанров» — `count(genres.json)`.
- «Категорий» — `count(categories.json)`.
- «Игр» — `count(data/games/*.json)`.
- «Уровней» — сумма `count(levels)` по всем играм.
- «AI-моделей» — `count(ai-models.json)`.
- «Паттернов» — `count(movement-patterns.json)`.
- «Эмодзи в банке» — `count(emoji-bank.json)`.
- «Пользователей» — `count(users.json)`.

**Топ игр** — только при `config.analytics_enabled = true`. Иначе блок скрыт.

**Последние действия** — 10 последних записей `audit.log`, где `action` — не `login`/`logout`/`public.*`.

### 2.2. Пустое состояние

Если нет игр: блок «Быстрый старт» — ссылки «Создать игру», «Создать жанр», «Загрузить эмодзи».

---

## 3. Жанры (`/admin/genres`)

### 3.1. Список

```
┌─ Жанры ────────────────────────────────────────────────────────┐
│  [+ Создать жанр]    [Восстановить дефолтные жанры]           │
├────────────────────────────────────────────────────────────────┤
│ ▲▼ │ Название             │ Slug            │ Движок    │ Игр │
├────┼──────────────────────┼─────────────────┼───────────┼─────┤
│ ▲▼ │ Lode Runner-подобные │ lode-runner     │ static    │  12 │
│ ▲▼ │ Arcade adventure     │ arcade-adventure│ static    │   8 │
│ ▲▼ │ Платформеры          │ platformer      │ horizontal│  35 │
│ ▲▼ │ Myth-like            │ myth-like       │ horizontal│   9 │
│ ▲▼ │ Вертикальные шутеры  │ vertical-shooter│ vertical  │  23 │
│    │                      │                 │           │     │
│    │                      │                 │ [✎] [🗑]  │     │
└────┴──────────────────────┴─────────────────┴───────────┴─────┘
```

**Колонки:**

- **▲▼** — изменение `sort_order` (кнопки ▲▼ меняют на `±10` и сохраняют через POST).
- **Название** — ссылка на редактирование.
- **Slug** — моноширинный.
- **Движок** — бейдж с коротким именем (`static`, `vertical`, `horizontal`), полное — во всплывающей подсказке.
- **Игр** — количество игр с этим `genre_id` (считается по `data/games/*.json`).
- **Действия** — ✎ / 🗑.

**Сортировка:** по `sort_order` asc.

**Кнопки:**

- **«+ Создать жанр»** → `/admin/genres/new`.
- **«Восстановить дефолтные жанры»** → POST на `/admin/genres/seed`; вызывает ту же логику, что CLI `seed-genres.php`; идемпотентен; flash «Добавлено N жанров, пропущено M».

### 3.2. Seed-команда `cli/seed-genres.php`

```bash
php cli/seed-genres.php
```

**Логика:**

1. Читает `data/genres.json` (или `[]`).
2. Читает все пять фикстур из `config/genre-defaults/*.json`:
   - `loderunner.json`;
   - `arcade-adventure.json`;
   - `platformer.json`;
   - `myth.json`;
   - `riverraid.json`.
3. Для каждой фикстуры:
   - если `id` уже есть в `genres.json` — **пропускает**;
   - если `id` есть, но `engine` отличается от фикстуры — **предупреждение**, пропуск, `audit.log` → `genre.seed_conflict`;
   - иначе создаёт запись с полным `engine_config`, `default_legend`, `default_controls`, `default_sounds` из фикстуры.
4. Атомарная запись через `FileStore::write`.
5. `audit.log` → `genre.seed` с числом добавленных.
6. Печатает итог: `Seeded N genres (M existed, 0 conflicts).`

**Идемпотентность:** повторный запуск не создаёт дублей.

### 3.3. Форма создания/редактирования

**URL:** `/admin/genres/new` (создание) и `/admin/genres/{id}` (редактирование).

**Вкладки страницы жанра:**

- **Основное** — поля.
- **Легенда жанра** — редактирование `default_legend` (см. §4).

#### 3.3.1. Вкладка «Основное»

```
┌─ Основное ─────────────────────────────────────────────────────┐
│                                                                 │
│  Slug *                    [lode-runner_________________]       │
│                            [Сгенерировать из названия]          │
│                                                                 │
│  Название *                [Lode Runner-подобные________]      │
│                                                                 │
│  Описание                  [Одноэкранные головоломки..._]      │
│                            (0–500)                              │
│                                                                 │
│  Движок *                  [static-screen ▼]                    │
│                            Lode Runner-подобные игры            │
│                                                                 │
│  Sort order                [10_____]                            │
│                                                                 │
│  ┌─ Расширенные параметры (read-only) ────────────────────┐    │
│  │  ▼ engine_config (JSON)                                │    │
│  │  ▼ default_legend (n элементов)                        │    │
│  │  ▼ default_controls (n действий)                       │    │
│  │  ▼ default_sounds (n событий)                          │    │
│  │                                                         │    │
│  │  ℹ️ Редактирование engine_config — при смене движка.   │    │
│  │     default_legend редактируется на вкладке «Легенда».│    │
│  │     default_controls/default_sounds наследуются        │    │
│  │     играми жанра.                                      │    │
│  └─────────────────────────────────────────────────────────┘    │
│                                                                 │
│  [Сохранить]      [Отмена]                                      │
└─────────────────────────────────────────────────────────────────┘
```

**Поля:**

| Поле | Тип | Валидация |
|---|---|---|
| Slug | text | 2–40, `^[a-z0-9-]+$`, уникален; на создании — автогенерация из названия (транслитерация); после сохранения — read-only с кнопкой «Изменить» (модалка с предупреждением «Изменение slug сломает существующие ссылки, если они есть») |
| Название | text | 2–80 |
| Описание | textarea | 0–500; счётчик символов |
| Движок | select | `static-screen` \| `vertical-scroll` \| `horizontal-scroll` |
| Sort order | number | целое |

**Блок «Расширенные параметры»** — раскрывающиеся секции с JSON:

- `engine_config` — pretty-printed JSON.
- `default_legend` — таблица emoji → role → name.
- `default_controls` — таблица action → keys.
- `default_sounds` — таблица event → параметры.

**Read-only** (изменение через смену движка или через вкладку «Легенда жанра»).

#### 3.3.2. Смена движка

При изменении поля «Движок» после сохранения — **модалка**:

```
⚠ Смена движка
Вы меняете движок с «static-screen» на «horizontal-scroll».

Будут перезаписаны:
  • engine_config
  • default_legend
  • default_controls
  • default_sounds

Это затронет все игры этого жанра (N игр). Уровни игр могут стать
несовместимыми с новым движком — их придётся пересоздать.

Для подтверждения введите slug жанра: [_______________]

[Отмена]                              [Подтвердить]
```

После подтверждения:

1. `engine_config`, `default_legend`, `default_controls`, `default_sounds` перезаписываются фикстурой нового движка (из `config/genre-defaults/`).
2. `audit.log` → `genre.engine_change` с `old`/`new` и `affected_games`.
3. Flash «Движок изменён. Параметры обновлены. N игр затронуто».

**Смена движка без сохранения (в форме до submit)** — просто обновляет блок «Расширенные параметры» на клиенте после AJAX-запроса `/admin/api/genre-defaults/{engine}.json`, чтобы админ видел, что будет записано.

### 3.4. Вкладка «Легенда жанра»

Редактор `default_legend` — идентичен редактору легенды игры (см. блок 7, §13), но с особенностями:

- Нет секции «Из жанра» (это и есть дефолт).
- Нет кнопки «Импортировать из жанра».
- Кнопка «Сбросить к пустой легенде» (модалка).
- Кнопка «Скопировать из другого жанра» (модалка с селектом).
- Предупреждение вверху: «Изменения затронут все игры этого жанра, которые не переопределили элемент локально. Изменения вступят в силу при следующем открытии игры.»

Сохранение — вместе с основными полями жанра (единый POST).

### 3.5. Удаление

**URL:** POST `/admin/genres/{id}/delete`.

**Проверка:**

1. Поиск игр с `genre_id === id` в `data/games/*.json`.
2. Если найдены — отказ: «Жанр используется играми: <список>. Сначала переназначьте или удалите эти игры.»
3. Иначе — модалка с вводом slug.

**После подтверждения:**

- Запись удаляется из `genres.json`.
- `audit.log` → `genre.delete`.
- Flash «Жанр „X" удалён».

### 3.6. Валидация (сводно)

| Поле | Правило |
|---|---|
| `slug` | `^[a-z0-9-]{2,40}$`, уникален |
| `name` | 2–80 |
| `description` | 0–500 |
| `engine` | один из 3 движков |
| `sort_order` | −10000 … 10000 |

При ошибке — красный текст рядом с полем, блок сохранения не выполняется.

### 3.7. Аудит

```json
{"ts":"...","user":"admin","action":"genre.create","target":"genre-platformer","details":{"engine":"horizontal-scroll"}}
{"ts":"...","user":"admin","action":"genre.update","target":"genre-platformer","details":{"changed":["name","sort_order"]}}
{"ts":"...","user":"admin","action":"genre.engine_change","target":"genre-platformer","details":{"old":"static-screen","new":"horizontal-scroll","affected_games":12}}
{"ts":"...","user":"admin","action":"genre.delete","target":"genre-platformer"}
{"ts":"...","user":"admin","action":"genre.seed","details":{"added":5,"skipped":0}}
{"ts":"...","user":"admin","action":"genre.seed_conflict","target":"genre-platformer","details":{"expected_engine":"horizontal-scroll","existing_engine":"static-screen"}}
{"ts":"...","user":"admin","action":"genre.default_legend_update","target":"genre-loderunner","details":{"changed":["added:🦹","removed:👾"]}}
```

---

## 4. Категории (`/admin/categories`)

### 4.1. Список (дерево)

```
┌─ Категории ────────────────────────────────────────────────────┐
│  [+ Создать категорию]                                         │
├────────────────────────────────────────────────────────────────┤
│  ▼ Аркады (arcade)                              order:10  [✎🗑] │
│      ▶ Шутеры (shooter)                         order:10  [✎🗑] │
│  ▶ Головоломки (puzzle)                         order:20  [✎🗑] │
│  ▶ Приключения (adventure)                      order:30  [✎🗑] │
└────────────────────────────────────────────────────────────────┘
```

**Каждый узел:**

- Раскрытие/сворачивание (▶/▼) — для узлов с детьми.
- Название — ссылка на редактирование.
- Slug в скобках — моноширинный.
- `sort_order`.
- Кнопки ▲▼ (изменение порядка **внутри родителя**).
- Кнопки ✎ / 🗑.

**Сортировка:** по `sort_order` внутри каждого родителя.

**Отображение:** рекурсивный обход дерева, глубина ≤ 5.

### 4.2. Форма создания/редактирования

**URL:** `/admin/categories/new` и `/admin/categories/{id}`.

```
┌─ Основное ─────────────────────────────────────────────────────┐
│                                                                 │
│  Slug *                    [shooter_______________]             │
│                            [Сгенерировать из названия]          │
│                                                                 │
│  Название *                [Шутеры_________________]           │
│                                                                 │
│  Описание                  [_______________________]            │
│                            (0–500)                              │
│                                                                 │
│  Родитель                  [— Корень — ▼]                       │
│                            (список всех категорий, кроме        │
│                             self и потомков)                    │
│                                                                 │
│  Sort order                [10_____]                            │
│                                                                 │
│  [Сохранить]      [Отмена]                                      │
└─────────────────────────────────────────────────────────────────┘
```

**Поля:**

| Поле | Тип | Валидация |
|---|---|---|
| Slug | text | 2–40, `^[a-z0-9-]+$`, уникален; read-only после сохранения |
| Название | text | 2–80 |
| Описание | textarea | 0–500 |
| Родитель | select | `null` или существующий `id`, не self, не потомок, глубина ≤ 5 |
| Sort order | number | целое |

**Селект «Родитель»:**

- Пункт «— Корень —» (`value=""`).
- Все категории, кроме self и его потомков (вычисляется рекурсивно).
- Опции с отступами для визуальной иерархии: `   └ Шутеры`.

### 4.3. Валидация иерархии

**Проверка `parent_id`:**

1. `null` или существующий `id`.
2. `parent_id !== self_id`.
3. **Нет цикла:** обход вверх по `parent_id` от выбранного родителя; если встретился `self_id` — ошибка «Нельзя назначить категорию потомком самой себя».
4. **Глубина:** `1 + depth(parent)` ≤ 5. При превышении — ошибка «Превышена максимальная глубина (5)».

**Сортировка:** внутри одного родителя `sort_order` уникален (при коллизии — автоинкремент до свободного).

### 4.4. Удаление

**URL:** POST `/admin/categories/{id}/delete`.

**Проверка:**

1. **Дочерние категории:** если есть хотя бы одна с `parent_id === id` — отказ «У категории есть дочерние: <список>. Сначала удалите или переназначьте их.»
2. **Использование в играх:** поиск `category_ids` в `data/games/*.json`; если найдена — отказ «Категория используется играми: <список>.»

**Иначе** — модалка с вводом slug. После удаления:

- Запись удаляется.
- `audit.log` → `category.delete`.
- Flash «Категория „X" удалена».

**Опция «Переназначить детей на дедушку»** — **не реализуется** (упрощение, безопаснее блокировать удаление).

### 4.5. Изменение порядка (▲▼)

**URL:** POST `/admin/categories/{id}/reorder` с `direction=up|down`.

**Логика:**

- Найти всех siblings (с тем же `parent_id`).
- Отсортировать по `sort_order`.
- Поменять `sort_order` с соседом.
- Атомарная запись.

Кнопки disabled, если элемент первый/последний в группе.

### 4.6. Валидация (сводно)

| Поле | Правило |
|---|---|
| `slug` | `^[a-z0-9-]{2,40}$`, уникален |
| `name` | 2–80 |
| `description` | 0–500 |
| `parent_id` | `null` \| существующий; не self; без циклов; глубина ≤ 5 |
| `sort_order` | −10000 … 10000, уникален в рамках родителя (при коллизии — автоинкремент) |

### 4.7. Аудит

```json
{"ts":"...","user":"admin","action":"category.create","target":"cat-shooter","details":{"parent_id":"cat-arcade"}}
{"ts":"...","user":"admin","action":"category.update","target":"cat-shooter","details":{"changed":["name","parent_id"]}}
{"ts":"...","user":"admin","action":"category.reorder","target":"cat-shooter","details":{"direction":"up","new_position":2}}
{"ts":"...","user":"admin","action":"category.delete","target":"cat-shooter"}
```

---

## 5. Импорт/экспорт справочников (жанры, категории)

### 5.1. Экспорт

**URL:** `GET /admin/genres/export`, `GET /admin/categories/export`.

Скачивает JSON-файл `genres.json` / `categories.json` как `genres-YYYYMMDD-HHMMSS.json`.

### 5.2. Импорт

**URL:** POST `/admin/genres/import`, `/admin/categories/import`.

**Процесс:**

1. Загрузка JSON-файла ≤ 1 MB.
2. Валидация синтаксиса JSON.
3. Валидация схемы (поля, типы, ограничения).
4. **Режим импорта** (выбирается в модалке):
   - **«Добавить»** — только новые записи (по `id`); существующие пропускаются.
   - **«Заменить полностью»** — текущий файл перезаписывается (с автоматическим бэкапом `genres-{timestamp}.bak` в `data/backups/`).
5. Атомарная запись.
6. `audit.log` → `genre.import` / `category.import`.
7. Flash с результатом.

**Защита:**

- При импорте категорий — проверка целостности иерархии (нет сирот, нет циклов).
- При импорте жанров — проверка уникальности `slug` и `id`.

---

## 6. Ссылочная целостность

Проверки при **удалении** сущностей:

| Сущность | Проверяется в | Что блокирует |
|---|---|---|
| Жанр | `data/games/*.json` | Игра с этим `genre_id` |
| Категория | `data/games/*.json` (category_ids) + `data/categories.json` (дочерние) | Игра с этим `cat_id`; наличие дочерних категорий |
| Игра | — | — |
| Уровень | — | — |
| Эмодзи | `legend`, `default_legend`, `levels[].grid`, `frames` | Использование в любом из этих мест |
| AI-модель | `legend[emoji].ai_model` в играх | Использование |
| Паттерн | `legend[emoji].movement_pattern` в играх | Использование |

Проверки реализуются **до** модалки подтверждения; при использовании — операция блокируется с указанием конкретных сущностей.

---

## 7. Критерии приёмки блока 4

1. **Вход.** Админ входит по имени/паролю; после выхода — редирект на `/admin/login`.
2. **Layout.** Сайдбар, topbar, флеш-сообщения, модалки — на всех страницах.
3. **Дашборд.** Счётчики отражают реальные данные; последние 10 действий видны; «Быстрый старт» — если нет игр.
4. **Список жанров.** 5 жанров после seed; ▲▼ меняют `sort_order`; бейджи движков; количество игр.
5. **Seed.** `php cli/seed-genres.php` создаёт 5 жанров; повторный запуск не дублирует; кнопка «Восстановить дефолтные жанры» работает.
6. **Создание жанра.** Slug автогенерируется; при дубликате — ошибка; при невалидном `engine` — ошибка.
7. **Смена движка.** Модалка с вводом slug; после подтверждения `engine_config`/`default_legend`/`default_controls`/`default_sounds` перезаписываются; `audit.log` → `genre.engine_change`.
8. **Удаление жанра.** Свободный — удаляется после ввода slug; используемый игрой — блокируется с указанием игр.
9. **Легенда жанра.** Вкладка доступна; редактирование `default_legend`; «Сбросить», «Скопировать из другого»; сохранение вместе с полями жанра.
10. **Список категорий.** Дерево с раскрытием; ▲▼ меняют порядок внутри родителя; глубина ≤ 5.
11. **Создание категории.** Селект «Родитель» не содержит self и потомков; при попытке создать цикл через подделку POST — ошибка валидации.
12. **Удаление категории.** С дочерними — блокируется; используемая игрой — блокируется; свободная — удаляется после ввода slug.
13. **Импорт/экспорт.** Экспорт скачивает JSON; импорт «Добавить» и «Заменить полностью» работают с бэкапом.
14. **Ссылочная целостность.** При попытке удалить используемый жанр/категорию — отказ с перечислением.
15. **CSRF.** POST на удаление без токена — 403.
16. **Аудит.** Все операции записаны в `audit.log` с корректными `action`/`target`/`details`.
17. **Обратная совместимость.** Все критерии блоков 1–3 выполняются.

---

**Конец блока 4 из 12.**

Следующий блок — **Библиотеки AI-моделей и паттернов движения** (список, фильтры, поиск, формы кастомных моделей, визуализация траекторий, seed-команды, аудит).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 5. Библиотеки AI-моделей и паттернов движения

## 1. Общее описание

Две **общие библиотеки**, разделяемые всеми тремя движками (`static-screen`, `vertical-scroll`, `horizontal-scroll`):

- **`ai-models.json`** — 50 AI-моделей, описывающих **поведение** мобов (преследование, патруль, стрельба, боссы, формации).
- **`movement-patterns.json`** — 50 паттернов движения, описывающих **параметрическую траекторию** (синусоида, спираль, маятник, самонаведение).

**Разделение функций:**

| | AI-модель | Паттерн движения |
|---|---|---|
| Отвечает на | «Куда идти?» (решение) | «По какой кривой?» (траектория) |
| Зависит от игрока | Да (chase, guard, ambush) | Нет (полностью предсказуем) |
| Применяется | Платформеры, экшен-платформеры, лабиринты | Вертикальные и горизонтальные шутеры, летающие мобы |
| Время | Реактивное | Детерминированное от `t` |

**Совмещение в `hybrid`-режиме** (см. блок 7): AI решает «куда», паттерн модулирует «как» (покачивание при преследовании).

**Ключевые принципы:**

- **Общие для всех движков.** Модель реализуется один раз в `ai-core.js`, активируется, если `supported_engines` содержит движок игры.
- **Параметрические.** Каждая модель и паттерн имеют схему параметров с типами, диапазонами, дефолтами.
- **Встроенные + кастомные.** 50 + 50 встроенных (`custom: false`) read-only; кастомные (`custom: true`) создаются в админке.
- **Ссылочная целостность.** Удаление блокируется, если модель/паттерн используется в легенде хотя бы одной игры.

---

## 2. Схема `ai-models.json`

```json
[
  {
    "id": "chase_with_vision",
    "name": "Преследование при обнаружении",
    "description": "Моб преследует игрока в радиусе vision_range; теряет цель при выходе из lose_range",
    "category": "pursuit",
    "supported_engines": ["static-screen", "horizontal-scroll"],
    "reference": "Bomberman (1983, Hudson Soft)",
    "params": [
      {"key": "speed", "type": "number", "min": 0.1, "max": 3.0, "default": 0.5, "unit": "tiles/frame"},
      {"key": "vision_range", "type": "number", "min": 1, "max": 30, "default": 8, "unit": "tiles"},
      {"key": "lose_range", "type": "number", "min": 1, "max": 40, "default": 15, "unit": "tiles"},
      {"key": "turn_on_wall", "type": "boolean", "default": true},
      {"key": "turn_on_edge", "type": "boolean", "default": true},
      {"key": "pathfinding", "type": "enum", "options": ["direct", "greedy", "astar"], "default": "greedy"}
    ],
    "custom": false,
    "created_at": "2026-10-21T10:00:00Z",
    "updated_at": "2026-10-21T10:00:00Z"
  }
]
```

### 2.1. Поля

| Поле | Обязательно | Правило |
|---|---|---|
| `id` | ✅ | `^[a-z][a-z0-9_]{1,40}$`, уникален |
| `name` | ✅ | 2–80 |
| `description` | ✅ | 10–300 |
| `category` | ✅ | `movement` \| `pursuit` \| `combat` \| `advanced` \| `boss` |
| `supported_engines` | ✅ | непустой массив ⊆ `{static-screen, vertical-scroll, horizontal-scroll}` |
| `reference` | ❌ | 0–120; для встроенных — обязательно, для кастомных — опционально |
| `params[]` | ✅ (может быть `[]`) | ≤ 20 элементов |
| `custom` | ✅ | `false` для встроенных, `true` для созданных в админке |
| `created_at`, `updated_at` | ✅ | ISO 8601 |

### 2.2. Схема `params[]`

Каждый параметр:

```json
{
  "key": "vision_range",
  "type": "number",
  "min": 1,
  "max": 30,
  "default": 8,
  "unit": "tiles",
  "options": null
}
```

| Поле | Для типа | Правило |
|---|---|---|
| `key` | все | `^[a-z][a-z0-9_]{1,30}$`, уникален в `params[]` |
| `type` | все | `number` \| `boolean` \| `enum` \| `string` |
| `min`, `max` | `number` | min < max; default в диапазоне |
| `options` | `enum` | непустой массив строк; default ∈ options |
| `default` | все | обязателен; тип соответствует `type` |
| `unit` | опц. | для отображения (`tiles`, `ms`, `tiles/frame`) |

---

## 3. Схема `movement-patterns.json`

```json
[
  {
    "id": "sine_horizontal",
    "name": "Горизонтальная синусоида",
    "description": "Полёт вниз с горизонтальными колебаниями",
    "category": "oscillating",
    "supported_engines": ["vertical-scroll", "horizontal-scroll"],
    "reference": "Gradius (1985, Konami)",
    "params": [
      {"key": "speed", "type": "number", "min": 0.1, "max": 10.0, "default": 2.0},
      {"key": "amplitude", "type": "number", "min": 0.5, "max": 10.0, "default": 3.0},
      {"key": "frequency_hz", "type": "number", "min": 0.05, "max": 3.0, "default": 0.5},
      {"key": "phase", "type": "number", "min": 0, "max": 6.28, "default": 0}
    ],
    "trajectory_hint": "x(t) = base_x + amplitude·sin(2π·f·t + φ); y(t) = base_y + speed·t",
    "on_finish": "despawn",
    "custom": false,
    "created_at": "2026-10-21T10:00:00Z",
    "updated_at": "2026-10-21T10:00:00Z"
  }
]
```

### 3.1. Дополнительно к AI-моделям

| Поле | Правило |
|---|---|
| `category` | `linear` \| `oscillating` \| `curved` \| `homing` \| `composite` \| `bit8-classic` |
| `trajectory_hint` | 0–200; справочная формула (не исполняется) |
| `on_finish` | `despawn` \| `straight_down` \| `loop`; поведение при истечении `duration_ms` (если применимо) |

---

## 4. Полный перечень 50 AI-моделей

### 4.1. Категория `movement` (12)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `stationary` | Неподвижный | — | все | Классика 8-бит |
| `patrol_horizontal` | Патруль по горизонтали | `speed`, `range`, `turn_on_wall`, `turn_on_edge`, `pause_at_ends_ms` | все | *Super Mario Bros.* (1985, Nintendo) |
| `patrol_vertical` | Патруль по вертикали | `speed`, `range`, `pause_at_ends_ms` | static, vertical | *Lode Runner* (1983, Broderbund) |
| `patrol_waypoints` | Патруль по точкам | `speed`, `waypoints[]`, `loop`, `pause_at_waypoints_ms` | все | *Bubble Bobble* (1986, Taito) |
| `patrol_random` | Случайные развороты | `speed`, `turn_chance_per_sec`, `turn_on_wall` | все | *Mr. Do!* (1982, Universal) |
| `wander` | Блуждание | `speed`, `change_dir_ms`, `turn_on_wall` | static, horizontal | *Pac-Man* (1980, Namco) |
| `random_walk` | Случайное блуждание | `speed`, `change_dir_ms` | static | *Atic Atac* (1983, Ultimate) |
| `ghost_phase` | Проходит сквозь стены | `speed`, `phase_interval_ms` | static | *Bubble Bobble* (1986, Taito) |
| `ladder_user` | Использует лестницы | `speed`, `pathfinding` | static, horizontal | *Lode Runner* (1983) |
| `platform_edge_aware` | Учитывает края платформ | `speed`, `turn_on_edge`, `ledge_jump_chance` | horizontal | *Super Mario Bros.* (1985) |
| `jumper` | Прыгающий | `jump_power`, `jump_interval_ms`, `horizontal_drift` | horizontal | *Donkey Kong* (1981, Nintendo) |
| `mimic_player_mirror` | Зеркалит игрока | `speed`, `axis` (`horizontal`/`vertical`/`both`) | static, horizontal | *Shadow of the Beast* (1989, Psygnosis) |

### 4.2. Категория `pursuit` (12)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `chase` | Прямое преследование | `speed`, `turn_on_wall`, `pathfinding` | все | *Pac-Man* (1980) — Blinky |
| `chase_with_vision` | Преследование при обнаружении | `speed`, `vision_range`, `lose_range`, `pathfinding` | все | *Bomberman* (1983) |
| `chase_astar` | Преследование с A* | `speed`, `grid_weight` | static, horizontal | *The Trap Door* (1986, Piranha) |
| `guard` | Страж | `patrol_speed`, `chase_speed`, `post_position`, `vision_range`, `return_after_ms` | static, horizontal | *Super Mario Bros.* (Hammer Bro) |
| `ambush` | Засада | `wait_position`, `trigger_range`, `then_ai`, `speed` | static, horizontal | *Atic Atac* (1983) |
| `kamikaze` | Камикадзе | `speed`, `trigger_range`, `explosion_damage`, `explosion_radius` | все | *Bomb Jack* (1984, Tehkan) |
| `flee` | Убегает | `speed`, `flee_range`, `turn_on_wall` | static | *Pac-Man* (Clyde) |
| `pacman_ghost` | Призраки Pac-Man | `mode` (`blinky`/`pinky`/`inky`/`clyde`), `speed`, `scatter_duration_ms` | static | *Pac-Man* (1980) |
| `trapper` | Отрезает путь | `speed`, `cutoff_distance`, `pathfinding` | static | *Bubble Bobble* (1986) |
| `line_of_sight_chaser` | Атакует по прямой в поле зрения | `speed`, `vision_range`, `vision_angle_deg` | horizontal | *Myth* (1989, System 3) |
| `zombie_chase` | Медленное преследование | `speed`, `vision_range` | все | *Zombies* (1984, Loriciels) |
| `predictive_chase` | С предсказанием | `speed`, `lookahead_frames`, `pathfinding` | horizontal, vertical | *Defender* (1980, Williams) |

### 4.3. Категория `combat` (10)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `shooter_stationary` | Неподвижная турель | `attack_rate_ms`, `projectile`, `direction` | все | *Defender* (1980) |
| `shooter_patrol` | Стрелок при патруле | `patrol_speed`, `attack_rate_ms`, `projectile` | static, horizontal | *Scramble* (1981, Konami) |
| `shooter_aimed` | Стреляет точно в игрока | `attack_rate_ms`, `projectile`, `lead_time_ms` | vertical, horizontal | *Space Invaders* (1978, Taito) |
| `shooter_lead` | С упреждением | `attack_rate_ms`, `projectile`, `prediction_strength` | vertical | *Sopwith* (1984, BMB) |
| `turret_rotating` | Вращающаяся турель | `angular_speed`, `attack_rate_ms`, `projectile` | static, horizontal | *Defender* (1980) |
| `bomber` | Бросает бомбы вниз | `attack_rate_ms`, `projectile`, `altitude_min` | vertical | *River Raid* (1982, Activision) |
| `spawner` | Призывает мобов | `spawn_emoji`, `rate_ms`, `max_alive`, `spawn_radius` | все | *Bubble Bobble* (1986) |
| `sniper` | Снайпер | `attack_rate_ms`, `aim_time_ms`, `projectile` | static, horizontal | *Berserk* (1980, Stern) |
| `artillery` | Артиллерия | `attack_rate_ms`, `projectile`, `explosion_radius` | static, horizontal | *Scramble* (1981) |
| `bomb_dropper` | Сбрасывает бомбы | `attack_rate_ms`, `drop_delay_ms`, `projectile` | vertical | *Moon Patrol* (1982, Irem) |

### 4.4. Категория `advanced` (10)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `item_guard` | Охраняет предмет | `guard_position`, `vision_range`, `speed` | static, horizontal | *Gauntlet* (1985, Atari) |
| `hive_mind` | Роевое поведение | `group_cooldown_ms`, `role` (`flanker`/`blocker`/`chaser`) | static, vertical | *Spy Hunter* (1983, Bally Midway) |
| `wave_attack` | Атака волнами | `wave_size`, `wave_interval_ms`, `wave_speed_up_factor` | vertical | *Space Invaders* (1978) |
| `revenge` | Ускорение после убийства сородича | `trigger_range`, `speed_boost`, `duration_ms` | static, horizontal | *Gauntlet* (1985) |
| `healer` | Лечит других мобов | `heal_amount`, `heal_rate_ms`, `heal_radius` | static, horizontal | *Gauntlet* (Wizard) |
| `summoner` | Призывает боссов | `boss_emoji`, `cooldown_ms`, `max_bosses` | static, horizontal | *Bubble Bobble* (1986) |
| `swarm` | Летит роем | `swarm_radius`, `swarm_speed`, `cohesion` | vertical | *Galaga* (1981, Namco) |
| `formation_leader` | Лидер формации | `formation_type` (`v`/`line`/`echelon`), `spacing`, `follower_ai` | vertical | *Galaga* (1981) |
| `escape_run` | Убегает при низком HP | `hp_trigger_ratio`, `flee_speed`, `flee_direction` | все | *Bubble Bobble* (1986) |
| `splitter` | Делится при смерти | `split_count`, `child_emoji`, `child_hp` | static, horizontal | *Dragon Quest* (1986, Enix) — Slimes |

### 4.5. Категория `boss` (6)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `boss_phases` | Босс с фазами | `phases[]` (`{hp_below, ai_model, ai_params}`) | все | *Wonder Boy* (1986, Sega) |
| `boss_pattern_cycle` | Циклический паттерн | `cycle[]` (`{duration_ms, movement_pattern, ai_model}`) | все | *Pac-Man* (1980) — финальный босс |
| `boss_summon_wave` | Вызов волн | `spawn_emoji`, `wave_size`, `cooldown_ms`, `base_ai_model` | static, vertical, horizontal | *Bubble Bobble* (1986) |
| `boss_weak_point` | Уязвимая точка | `weak_point_offset`, `invulnerable_period_ms` | horizontal | *Wonder Boy* (1986) |
| `boss_enrage` | Ярость при низком HP | `hp_trigger_ratio`, `speed_multiplier`, `attack_rate_multiplier` | все | *Asterix* (1983, Atari) |
| `boss_multi_head` | Многоголовый | `heads[]` (`{offset, hp, ai_model}`), `shared_hp` | horizontal, static | *Myth* (1989) — Гидра |

---

## 5. Полный перечень 50 паттернов движения

### 5.1. Категория `linear` (5)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `straight_down` | Прямолинейно вниз | `speed` | vertical, horizontal | *Space Invaders* (1978) |
| `straight_up` | Прямолинейно вверх | `speed` | vertical | *River Raid* (1982) — заправки |
| `diagonal` | Диагональ | `speed`, `angle_deg` (0–180) | vertical, horizontal | *Scramble* (1981) |
| `horizontal_scan` | Горизонтальный проход | `speed`, `direction` (`left`/`right`) | horizontal | *Defender* (1980) |
| `vertical_scan` | Вертикальный проход | `speed`, `direction` (`up`/`down`) | vertical | *Berserk* (1980) |

### 5.2. Категория `oscillating` (12)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `sine_horizontal` | Горизонтальная синусоида | `speed`, `amplitude`, `frequency_hz`, `phase` | vertical, horizontal | *Gradius* (1985, Konami) |
| `sine_vertical` | Вертикальная синусоида | `speed`, `amplitude`, `frequency_hz`, `phase` | vertical | *Xevious* (1982, Namco) |
| `zigzag` | Зигзаг | `speed`, `segment_length`, `angle_deg` (15–75) | vertical, horizontal | *Xevious* (1982) |
| `pendulum` | Маятник | `anchor_x`, `anchor_y`, `length`, `amplitude_deg`, `frequency_hz` | vertical, horizontal | *Bubble Bobble* (1986) |
| `sawtooth` | Пилообразный | `speed`, `amplitude`, `period_tiles` | vertical, horizontal | *Defender* (1980) |
| `triangle_wave` | Треугольная волна | `speed`, `amplitude`, `period_tiles` | vertical, horizontal | *Scramble* (1981) |
| `bounce` | Отскок от краёв | `speed_x`, `speed_y`, `edge_behavior` (`bounce`/`wrap`/`despawn`) | static, vertical, horizontal | *Arkanoid* (1986, Taito) |
| `spring` | Пружина | `speed`, `compression_ratio`, `period_ms` | horizontal | *Donkey Kong* (1981) |
| `tremolo` | Дрожание | `speed`, `jitter_amplitude`, `jitter_frequency` | vertical, horizontal | *Bubble Bobble* (1986) |
| `pulse` | Приближение-удаление | `base_x`, `base_y`, `min_distance`, `max_distance`, `period_ms` | vertical, horizontal | *Galaga* (1981) |
| `figure8_small` | Малая восьмёрка | `center_x`, `center_y`, `amplitude`, `frequency_hz` | vertical, horizontal | *Robotron 2084* (1982, Williams) |
| `random_jitter` | Случайное дрожание | `base_speed`, `jitter_amplitude`, `jitter_frequency` | vertical, horizontal | *Asteroids* (1979, Atari) |

### 5.3. Категория `curved` (11)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `circular` | Круг | `center_x`, `center_y`, `radius`, `angular_speed`, `direction` | static, vertical, horizontal | *Defender* (1980) |
| `spiral_in` | Спираль внутрь | `center_x`, `center_y`, `radius_start`, `radius_end`, `angular_speed`, `duration_ms`, `direction` | vertical | *Galaga* (1981) — пикирование |
| `spiral_out` | Спираль наружу | то же | vertical | *Galaga* (1981) |
| `helix` | Винтовая спираль | `base_x`, `speed_vertical`, `radius`, `angular_speed` | vertical | *Xevious* (1982) |
| `figure8` | Восьмёрка | `center_x`, `center_y`, `amplitude_x`, `amplitude_y`, `frequency_hz` | vertical, horizontal | *Pac-Man* (1980) — Clyde |
| `parabola` | Парабола | `start_x`, `start_y`, `velocity_x`, `velocity_y`, `gravity` | horizontal, static | *Donkey Kong* (1981) — бочки |
| `loop` | Мёртвая петля | `center_x`, `center_y`, `radius`, `angular_speed`, `direction` | vertical, horizontal | *Scramble* (1981) |
| `s_curve` | S-кривая | `amplitude`, `period_tiles`, `speed` | vertical, horizontal | *Sopwith* (1984, BMB) |
| `infinity` | Бесконечность | `center_x`, `center_y`, `amplitude_x`, `amplitude_y`, `frequency_hz` | vertical, horizontal | *Defender* (1980) |
| `orbit` | Орбита | `center_x`, `center_y`, `radius`, `angular_speed`, `direction` | static, vertical, horizontal | *Asteroids* (1979) — НЛО |
| `arc_swing` | Дуговое качание | `anchor_x`, `anchor_y`, `radius`, `sweep_deg`, `period_ms` | vertical, horizontal | *Donkey Kong* (1981) |

### 5.4. Категория `homing` (7)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `homing_direct` | Прямое самонаведение | `speed`, `lifetime_ms` | vertical, horizontal | *Missile Command* (1980, Atari) |
| `homing_curved` | С ограниченным поворотом | `speed`, `turn_rate_deg_per_sec`, `tracking_time_ms` | vertical, horizontal | *Defender* (1980) — Baiters |
| `dive` | Пикирование | `speed_start`, `speed_end`, `accel`, `lock_target_at` | vertical | *Xevious* (1982) |
| `swoop` | Заход с разворотом | `speed`, `swoop_depth`, `turn_radius`, `reenter` | vertical | *Galaga* (1981) |
| `kamikaze_dive` | Камикадзе-пикирование | `speed`, `trigger_range`, `explosion_radius` | vertical | *Bomb Jack* (1984) |
| `tracking_missile` | Самонаводящаяся ракета | `speed`, `turn_rate_deg_per_sec`, `fuel_ms` | vertical | *Missile Command* (1980) |
| `lead_pursuit` | С упреждением | `speed`, `lookahead_frames` | vertical | *Sopwith* (1984) |

### 5.5. Категория `composite` (8)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `bezier` | Кривая Безье | `control_points[]` (до 6), `duration_ms`, `loop` | static, vertical, horizontal | *Moon Patrol* (1982, Irem) |
| `scatter` | Хаотичное движение | `speed`, `change_dir_ms`, `turn_angle_max_deg` | static, vertical, horizontal | *Asteroids* (1979) |
| `teleport` | Телепортация | `teleport_interval_ms`, `target_zone` (`random`/`fixed`) | static, horizontal | *Bubble Bobble* (1986) |
| `barrel_roll` | Бочка | `speed`, `roll_radius`, `roll_frequency_hz` | vertical | *Scramble* (1981) |
| `wave_packet` | Волновой пакет | `base_speed`, `wave_amplitude`, `wave_frequency_hz`, `packet_length` | vertical | *Gradius* (1985) |
| `constrained_random` | Ограниченный дрейф | `speed`, `drift_radius`, `change_dir_ms` | static, vertical, horizontal | *Asteroids* (1979) |
| `stop_and_go` | Стоп-энд-гоу | `move_speed`, `move_ms`, `pause_ms`, `direction` | vertical, horizontal | *Space Invaders* (1978) |
| `reverse_on_wall` | Отражение от стен | `speed`, `wall_behavior` (`reflect`/`despawn`) | static, horizontal | *Arkanoid* (1986) |

### 5.6. Категория `bit8-classic` (7)

| id | Название | Ключевые параметры | Движки | Референс |
|---|---|---|---|---|
| `space_invaders_march` | Маршировка Space Invaders | `initial_speed`, `acceleration_per_kill`, `step_dx`, `step_dy` | vertical | *Space Invaders* (1978) |
| `galaga_formation` | Формация Galaga | `formation_type` (`v`/`line`/`arc`), `spacing`, `sway_amplitude`, `sway_frequency` | vertical | *Galaga* (1981) |
| `galaga_dive` | Пикирование Galaga | `dive_speed`, `spiral_radius`, `return_to_formation` | vertical | *Galaga* (1981) |
| `defender_scan` | Скан Defender | `scan_speed`, `wave_amplitude`, `wave_frequency` | vertical | *Defender* (1980) |
| `xevious_zigzag` | Зигзаг Xevious | `zigzag_amplitude`, `zigzag_frequency`, `forward_speed` | vertical | *Xevious* (1982) |
| `pacman_ghost_door` | Выход из домика | `exit_delay_ms`, `exit_speed`, `exit_path[]` | static | *Pac-Man* (1980) |
| `asteroids_drift` | Дрейф Asteroids | `speed`, `rotation_rate_deg_sec`, `screen_wrap` | vertical | *Asteroids* (1979) |

---

## 6. Seed-команды

### 6.1. `cli/seed-ai-models.php`

```bash
php cli/seed-ai-models.php
```

**Логика:**

1. Читает `data/ai-models.json` (или `[]`).
2. Читает фикстуры из `config/ai-defaults/`:
   - `movement.json` (12);
   - `pursuit.json` (12);
   - `combat.json` (10);
   - `advanced.json` (10);
   - `boss.json` (6).
3. Для каждой записи:
   - если `id` уже существует с `custom: false` — **пропуск**;
   - если `id` существует с `custom: true` — **конфликт**: пропуск с предупреждением (не перезаписываем пользовательское);
   - иначе — добавление с `custom: false`.
4. Атомарная запись.
5. `audit.log` → `ai_models.seed` с числом добавленных.
6. Печатает итог: `Seeded 50 AI models (N existed, M conflicts).`

**Идемпотентность:** повторный запуск не создаёт дублей.

### 6.2. `cli/seed-movement-patterns.php`

Аналогично; фикстуры из `config/pattern-defaults/`:
- `linear.json` (5);
- `oscillating.json` (12);
- `curved.json` (11);
- `homing.json` (7);
- `composite.json` (8);
- `bit8-classic.json` (7).

Печатает: `Seeded 50 movement patterns (N existed, M conflicts).`

### 6.3. Кнопки «Восстановить встроенные»

В разделах «AI-модели» и «Паттерны движения» — кнопки, вызывающие те же seed-команды через POST. Flash: «Добавлено N, пропущено M, конфликтов K».

---

## 7. UI: раздел «AI-модели» (`/admin/ai-models`)

### 7.1. Список

```
┌─ AI-модели ────────────────────────────────────────────────────┐
│  [+ Создать кастомную модель]    [Восстановить встроенные]    │
├────────────────────────────────────────────────────────────────┤
│  Фильтр:                                                       │
│    Категория: [Все] [movement] [pursuit] [combat] [advanced]   │
│               [boss]                                           │
│    Движок:    [Все ▼]                                          │
│    Тип:       [Все] [Встроенные] [Кастомные]                  │
│  Поиск: [______________________________________]              │
├────────────────────────────────────────────────────────────────┤
│  ID                    │ Название                │ Кат. │ Движки │
├────────────────────────┼─────────────────────────┼──────┼────────┤
│  stationary            │ Неподвижный             │ mov. │ все    │
│  patrol_horizontal     │ Патруль по горизонтали  │ mov. │ все    │
│  chase_with_vision     │ Преследование при обн.  │ pur. │ st,hs  │
│  ...                                                           │
│                                      [👁] [✎] [🗑]              │
│                                                                │
│  Показано: 24 из 50                                            │
└────────────────────────────────────────────────────────────────┘
```

**Колонки:**

- `id` — моноширинный.
- `name`.
- `category` — бейдж с цветом.
- `supported_engines` — иконки-бейджи (`st` / `vs` / `hs`).
- `custom` — значок «встроенная» (замок) или «кастомная» (карандаш).
- Действия: 👁 (просмотр), ✎ (редактировать — только кастомные), 🗑 (удалить — только кастомные).

**Фильтры:** по категории (5 кнопок-вкладок), по движку (выпадающий), по типу (все / встроенные / кастомные), поиск по `id`/`name`/`description`/`reference`.

**Пагинация:** 25 на страницу.

**Пустое состояние:** «Нет AI-моделей. Нажмите „Восстановить встроенные" для заполнения библиотеки.»

### 7.2. Просмотр встроенной модели

Модалка или отдельная страница:

```
┌─ chase_with_vision ────────────────────────────────────────────┐
│                                                                 │
│  Название:      Преследование при обнаружении                  │
│  Категория:     pursuit                                        │
│  Движки:        static-screen, horizontal-scroll               │
│  Референс:      Bomberman (1983, Hudson Soft)                  │
│                                                                 │
│  Описание:      Моб преследует игрока в радиусе vision_range;  │
│                 теряет цель при выходе из lose_range           │
│                                                                 │
│  ┌─ Параметры ────────────────────────────────────────────┐    │
│  │  key              │ type    │ min  │ max  │ default    │    │
│  ├───────────────────┼─────────┼──────┼──────┼────────────┤    │
│  │  speed            │ number  │ 0.1  │ 3.0  │ 0.5        │    │
│  │  vision_range     │ number  │ 1    │ 30   │ 8          │    │
│  │  lose_range       │ number  │ 1    │ 40   │ 15         │    │
│  │  turn_on_wall     │ boolean │ —    │ —    │ true       │    │
│  │  turn_on_edge     │ boolean │ —    │ —    │ true       │    │
│  │  pathfinding      │ enum    │ direct, greedy, astar    │    │
│  └────────────────────────────────────────────────────────┘    │
│                                                                 │
│  🔒 Встроенная модель. Редактирование недоступно.              │
│                                                                 │
│  [Клонировать для редактирования]                     [Закрыть]│
└────────────────────────────────────────────────────────────────┘
```

**Кнопка «Клонировать для редактирования»** — создаёт кастомную копию с `id` по шаблону `{оригинал}_copy`; при конфликте — `_copy_2`, `_copy_3`. Открывает форму создания с заполненными полями.

### 7.3. Создание/редактирование кастомной модели

Форма:

```
┌─ Новая AI-модель ──────────────────────────────────────────────┐
│                                                                 │
│  ID *             [my_custom_ai________________]               │
│                   (snake_case, автогенерация из названия)      │
│                                                                 │
│  Название *       [Моя модель___________________]              │
│                                                                 │
│  Описание *       [Описание поведения___________]              │
│                   (10–300)                                      │
│                                                                 │
│  Категория *      [pursuit ▼]                                   │
│                                                                 │
│  Референс         [_______________________________] (опц.)    │
│                                                                 │
│  Поддерживаемые движки *                                        │
│   ☑ static-screen  ☑ vertical-scroll  ☑ horizontal-scroll    │
│                                                                 │
│  ── Параметры ──                                                │
│  ┌────────────────────────────────────────────────────────┐    │
│  │ ≡ speed  number  0.1 – 3.0  default 0.5  unit tiles/frame │
│  │ ≡ vision_range  number  1 – 30  default 8              │    │
│  │ ...                                                     │    │
│  └────────────────────────────────────────────────────────┘    │
│  [+ Добавить параметр]                                         │
│                                                                 │
│  [Сохранить]   [Отмена]                                        │
└────────────────────────────────────────────────────────────────┘
```

**Поля `params[]`** — динамический список:
- Drag-handle ≡ для переупорядочивания.
- `key` — snake_case, автогенерация из названия параметра (если задано).
- `type` — селект: `number` / `boolean` / `enum` / `string`.
- `min`, `max` — для `number` (обязательно).
- `options` — для `enum` (список строк через запятую).
- `default` — обязателен, тип соответствует `type`.
- `unit` — опционально.
- Кнопка ✕ — удалить.

**Валидация:**
- `id` уникален, `^[a-z][a-z0-9_]{1,40}$`.
- `name` 2–80.
- `description` 10–300.
- `category` ∈ 5.
- `supported_engines` непуст.
- `params.length ≤ 20`.
- `params[].key` уникален, `^[a-z][a-z0-9_]{1,30}$`.
- `params[].default` валиден для `type`.

При ошибке — красный текст у поля; сохранение блокируется.

### 7.4. Удаление кастомной модели

**URL:** POST `/admin/ai-models/{id}/delete`.

**Проверка:** если модель используется в `legend[emoji].ai_model` хотя бы одной игры — **блокировка** с перечнем игр.

**Иначе** — модалка с вводом `id`. После удаления — `audit.log` → `ai_model.delete`.

**Встроенные удалить нельзя** (кнопки нет).

---

## 8. UI: раздел «Паттерны движения» (`/admin/patterns`)

Аналогичен разделу «AI-модели» с особенностями:

### 8.1. Список

- Колонки: `id`, `name`, `category` (6 вкладок), `supported_engines`, `custom`.
- **У каждой строки — мини-анимация траектории** (canvas 120×60), обновляется на hover (экономия CPU).
- Фильтр по категории (6 кнопок-вкладок: `linear`, `oscillating`, `curved`, `homing`, `composite`, `bit8-classic`).

### 8.2. Просмотр с визуализацией

```
┌─ spiral_in ────────────────────────────────────────────────────┐
│                                                                 │
│  Название:      Спираль внутрь                                 │
│  Категория:     curved                                         │
│  Движки:        vertical-scroll                                │
│  Референс:      Galaga (1981, Namco)                           │
│                                                                 │
│  Описание:      Моб летит по сужающейся спирали к центру       │
│                                                                 │
│  ┌─ Визуализация ────────────────────────────────────────┐    │
│  │                                                        │    │
│  │         [canvas 640×360]                               │    │
│  │         Анимированная точка движется по кривой        │    │
│  │         Оси X/Y, сетка, метки времени                  │    │
│  └────────────────────────────────────────────────────────┘    │
│                                                                 │
│  Формула: r(t) = lerp(radius_start, radius_end, t/duration);  │
│            a(t) = ±angular_speed·t                             │
│                                                                 │
│  ┌─ Параметры ────────────────────────────────────────────┐    │
│  │  ... (аналогично AI-моделям)                          │    │
│  └────────────────────────────────────────────────────────┘    │
│                                                                 │
│  on_finish:  despawn                                           │
│                                                                 │
│  [Клонировать для редактирования]                     [Закрыть]│
└────────────────────────────────────────────────────────────────┘
```

**Визуализация:**

- Canvas 640×360, тёмный фон.
- Сетка, оси X/Y с метками.
- Анимированная точка (или несколько) летит по траектории с учётом `params`.
- Параметры можно менять слайдерами — анимация перезапускается.
- **Визуализация — доверенный код в админке.** Не исполняет произвольные формулы из `trajectory_hint`; для каждого встроенного паттерна — отдельная функция в JS.

### 8.3. Создание/редактирование кастомного паттерна

Форма — как у AI-модели, плюс:

- `trajectory_hint` — textarea (справочная формула, не исполняется).
- `on_finish` — селект: `despawn` / `straight_down` / `loop`.

**Визуализация в реальном времени:** canvas 480×270 рядом с формой, обновляется при изменении параметров. Для кастомных паттернов с уникальным `id`, не совпадающим со встроенным, — заглушка: «Визуализация появится после реализации паттерна в движке».

---

## 9. Валидация (сводно)

| Правило | Где | Действие при провале |
|---|---|---|
| `id` уникален | Save | Ошибка у поля `id` |
| `id` соответствует `^[a-z][a-z0-9_]{1,40}$` | Save | Ошибка |
| `name` 2–80 | Save | Ошибка |
| `description` 10–300 | Save | Ошибка |
| `category` ∈ допустимых | Save | Ошибка |
| `supported_engines` непуст, ⊆ 3 движков | Save | Ошибка |
| `params.length ≤ 20` | Save | Ошибка |
| `params[].key` уникален | Save | Ошибка |
| `params[].default` валиден для `type` | Save | Ошибка |
| `params[].min < max` (для `number`) | Save | Ошибка |
| `params[].default ∈ options` (для `enum`) | Save | Ошибка |
| Удаление встроенной (`custom: false`) | Delete | Всегда заблокировано |
| Удаление используемой в игре | Delete | Блокировка с перечнем |

---

## 10. Ссылочная целостность

Удаление AI-модели проверяет:

- `data/games/*.json` → `legend[emoji].ai_model === id` хотя бы в одном мобе;
- `data/games/*.json` → `levels[].params.triggers[].then[].ai_model === id` (если триггеры ссылаются).

Удаление паттерна проверяет:

- `legend[emoji].movement_pattern === id`;
- `levels[].params.triggers[].then[].movement_pattern === id`.

При использовании — **блокировка** с перечнем игр/уровней.

---

## 11. Безопасность

| Угроза | Мера |
|---|---|
| XSS через `name`/`description`/`reference` | `htmlspecialchars()` при выводе |
| Исполнение произвольного кода через `trajectory_hint` | Не исполняется; только отображение как текст |
| Подмена `custom` через POST | Сервер игнорирует `custom` из формы; для встроенных всегда `false`, для кастомных — `true` |
| Удаление встроенных через подделку POST | Сервер проверяет `custom === true` перед удалением |
| Подмена `supported_engines` | Enum-валидация |
| DoS через огромные `params[]` | Лимит 20 |
| CSRF | Токен из блока 3 |
| Path traversal через `{id}` | Regex `^[a-z][a-z0-9_]{1,40}$` |

---

## 12. Аудит

```json
{"ts":"...","user":"admin","action":"ai_models.seed","details":{"added":50,"skipped":0,"conflicts":0}}
{"ts":"...","user":"admin","action":"ai_model.create","target":"my_custom_ai","details":{"category":"pursuit","engines":["static-screen","horizontal-scroll"],"params_count":6}}
{"ts":"...","user":"admin","action":"ai_model.update","target":"my_custom_ai","details":{"changed":["name","params[vision_range].default"]}}
{"ts":"...","user":"admin","action":"ai_model.clone","details":{"from":"chase_with_vision","to":"chase_with_vision_copy"}}
{"ts":"...","user":"admin","action":"ai_model.delete","target":"my_custom_ai"}
{"ts":"...","user":"admin","action":"ai_model.delete_blocked","target":"chase_with_vision","details":{"used_in_games":["game-001","game-005"]}}
{"ts":"...","user":"admin","action":"patterns.seed","details":{"added":50,"skipped":0,"conflicts":0}}
{"ts":"...","user":"admin","action":"pattern.create","target":"my_spiral","details":{"category":"curved","engines":["vertical-scroll"]}}
```

---

## 13. Критерии приёмки блока 5

### 13.1. Seed

1. **`cli/seed-ai-models.php`** создаёт 50 AI-моделей; повторный запуск не дублирует; конфликты логируются.
2. **`cli/seed-movement-patterns.php`** создаёт 50 паттернов; повторный запуск идемпотентен.
3. **Кнопки «Восстановить встроенные»** в разделах работают через POST; flash-сообщение с числами.

### 13.2. Полнота библиотек

4. **`data/ai-models.json`** содержит **ровно 50** записей с `custom: false`; распределение по категориям: movement (12), pursuit (12), combat (10), advanced (10), boss (6).
5. **`data/movement-patterns.json`** содержит **ровно 50** записей; распределение: linear (5), oscillating (12), curved (11), homing (7), composite (8), bit8-classic (7).
6. **Каждая запись** содержит обязательные поля: `id`, `name`, `description`, `category`, `supported_engines`, `params[]`, `custom`, `created_at`, `updated_at`. Паттерны дополнительно — `trajectory_hint`, `on_finish`.

### 13.3. Раздел «AI-модели»

7. **Список** отображает все 50 моделей после seed; фильтры работают.
8. **Просмотр встроенной** — модалка со схемой `params[]`, кнопка «Клонировать».
9. **Клонирование** — создаёт копию с `_copy` в `id`; при конфликте — `_copy_2`.
10. **Создание кастомной** — форма; все поля валидируются.
11. **Редактирование кастомной** — работает; встроенные read-only.
12. **Удаление кастомной** — модалка ввода `id`; при использовании в игре — блокировка.
13. **Встроенные удалить нельзя** — кнопки нет.

### 13.4. Раздел «Паттерны движения»

14. **Список** отображает все 50 паттернов; фильтры, мини-анимации.
15. **Просмотр** — увеличенная анимация 640×360, оси, сетка, `trajectory_hint`.
16. **Создание/редактирование** — как у AI-моделей + `trajectory_hint` и `on_finish`.
17. **Визуализация** — для встроенных работает; для кастомных с уникальным `id` — заглушка.
18. **Удаление** — блокировка при использовании в играх.

### 13.5. Валидация и безопасность

19. **Дубликат `id`** — ошибка.
20. **Невалидный `params[]`** (тип, диапазон, default) — ошибка у поля.
21. **Пустой `supported_engines`** — ошибка.
22. **XSS в `name`** — экранировано при выводе.
23. **CSRF** — POST без токена — 403.
24. **Подмена `custom`** — игнорируется; встроенные не удаляются.

### 13.6. Аудит и обратная совместимость

25. **Аудит** — все операции с библиотеками записаны в `audit.log` с корректными `action`/`target`/`details`.
26. **Критерии блоков 1–4, 10** выполняются.
27. **Доступность в легенде** — на шаге 7 (легенда) модели и паттерны становятся выбираемыми в UI моба (селекторы фильтруются по `supported_engines`).

---

## 14. Оценка

**Ориентир:** 4–5 человеко-дней на разработчика (или ~1,5 рабочих дня с ИИ-ассистентом). Основное:

- **Подготовка 100 записей фикстур** (11 JSON-файлов) с корректными параметрами, диапазонами, референсами — самая трудоёмкая часть (~1–1,5 дня).
- **UI разделов** — списки с фильтрами, формы создания/редактирования, модалки просмотра (~1 день).
- **Визуализация 50 траекторий** — по 1 JS-функции на паттерн, ~1000 строк (~1 день).
- **Seed-команды** — 2 CLI (~0,3 дня).
- **Валидация и аудит** (~0,5 дня).
- **Отладка и тестирование** (~0,5 дня).

---

## 15. Что не делаем в блоке 5 и почему

- **Не создаём новые AI-модели/паттерны из UI в рамках seed** — библиотека фиксирована; кастомные создаются в админке, но не входят в seed.
- **Не реализуем map-based AI** (записанные вручную маршруты с координатами) — вне объёма; `patrol_waypoints` покрывает 90% случаев.
- **Не реализуем машинное обучение / нейросети** — вне объёма.
- **Не исполняем произвольные формулы** из `trajectory_hint` — только визуализация по `id` в админке; исполнение — в коде движка (блок 11).
- **Не реализуем редактор траекторий** (рисование мышью) — только параметрические паттерны.
- **Не реализуем формации** (V-строй, линия, эшелон) как отдельные модели — это слой над паттернами, реализуется в блоке 11 (`vertical-scroll`) и управляется через `mob.formation` (см. блок 7).

---

**Конец блока 5 из 13.**

Следующий блок — **Банк эмодзи** (PNG/GIF/SVG, PUA-кодпоинты, анимации, публичный эндпоинт, экспорт/импорт ZIP, интеграция с picker и renderer).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 6. Банк эмодзи

## 1. Общее описание

Самостоятельная подсистема для загрузки, каталогизации и управления **кастомными графическими эмодзи**, которые используются в легендах игр и сетках уровней наравне с Unicode-эмодзи.

**Назначение:**

- дать администратору возможность загружать собственные спрайты (растр или вектор, включая анимации) вместо ограниченного набора Unicode;
- обеспечить единый механизм для всех игр CMS;
- гарантировать безопасность (валидация, ре-энкод, санитизация SVG).

**Ключевые принципы:**

- **PUA-кодпоинты.** Каждый эмодзи получает кодпоинт `U+E000`…`U+F8FF` (6400 слотов) и используется в легендах/уровнях как обычный символ.
- **Три формата источников.** PNG, GIF (первый кадр), SVG (санитизированный до геометрии + градиенты, **не растеризуется**).
- **Анимации из кадров.** Последовательность существующих статичных эмодзи с индивидуальными длительностями; отдельного файла нет.
- **Кодпоинт не переиспользуется.** Удалённый `U+E042` не будет выдан повторно — защита от «перепутанных» ссылок в бэкапах и черновиках.
- **CMS занимается механикой, не эстетикой.** Банк не пикселизирует, не ресемплирует и не масштабирует спрайты. Как спрайт выглядит на экране — определяется `tile_size` в `engine_config`, CSS `image-rendering` и браузером.

**Изоляция от игр:** банк работает независимо. Загрузка, редактирование, удаление эмодзи не требует наличия игр. Использование в легенде и уровнях — блоки 7 и 8.

---

## 2. Схема данных

### 2.1. `data/emoji-bank.json`

```json
[
  {
    "id": "e001",
    "codepoint": "U+E000",
    "name": "Пиксельный рыцарь",
    "category": "персонажи",
    "type": "static",
    "source_format": "png",
    "stored_format": "png",
    "image_path": "media/emoji-bank/e001.png",
    "source_size": {"width": 32, "height": 32},
    "sanitized_hash": null,
    "frames": null,
    "loop_mode": null,
    "created_at": "2026-10-21T18:00:00Z",
    "updated_at": "2026-10-21T18:00:00Z"
  },
  {
    "id": "e002",
    "codepoint": "U+E001",
    "name": "Меч",
    "category": "оружие",
    "type": "static",
    "source_format": "svg",
    "stored_format": "svg",
    "image_path": "media/emoji-bank/e002.svg",
    "source_size": {"width": 64, "height": 64},
    "sanitized_hash": "sha256:7d3f8a9c...",
    "frames": null,
    "loop_mode": null,
    "created_at": "2026-10-21T18:01:00Z",
    "updated_at": "2026-10-21T18:01:00Z"
  },
  {
    "id": "e020",
    "codepoint": "U+E013",
    "name": "Рыцарь идёт",
    "category": "персонажи",
    "type": "animation",
    "source_format": "animation",
    "stored_format": null,
    "image_path": null,
    "source_size": null,
    "sanitized_hash": null,
    "frames": [
      {"codepoint": "U+E000", "duration_ms": 150},
      {"codepoint": "U+E001", "duration_ms": 150},
      {"codepoint": "U+E002", "duration_ms": 150},
      {"codepoint": "U+E001", "duration_ms": 150}
    ],
    "loop_mode": "loop",
    "created_at": "2026-10-21T19:00:00Z",
    "updated_at": "2026-10-21T19:00:00Z"
  }
]
```

### 2.2. Поля

| Поле | Обязательно | Правило |
|---|---|---|
| `id` | ✅ | `^e\d{3,}$`; назначается сервером последовательно |
| `codepoint` | ✅ | `U+E000`…`U+F8FF`; уникален; иммутабелен; не переиспользуется |
| `name` | ✅ | 2–80; уникальность не требуется |
| `category` | ✅ | `персонажи` \| `оружие` \| `природа` \| `UI` \| `символы` \| `другое` |
| `type` | ✅ | `static` \| `animation` |
| `source_format` | ✅ | `png` \| `gif` \| `svg` \| `animation` |
| `stored_format` | ✅ | `png` (для png/gif) \| `svg` (для svg) \| `null` (для animation) |
| `image_path` | ✅ | `media/emoji-bank/{id}.{png\|svg}` \| `null` (для animation) |
| `source_size` | ✅ | `{width, height}` (исходный размер после ре-энкода) \| `null` (для animation) |
| `sanitized_hash` | ❌ | `sha256:...` для SVG; `null` для растровых и animation |
| `frames` | ❌ | массив `{codepoint, duration_ms}`, 2–20, только для animation |
| `loop_mode` | ❌ | `loop` \| `once` \| `ping_pong`; только для animation |
| `created_at`, `updated_at` | ✅ | ISO 8601 |

**Ограничения размеров:** `source_size.width`, `source_size.height` ∈ [8, 256] — технический лимит против decompression bomb и мусора, не эстетика.

### 2.3. `data/emoji-bank-counter.json`

```json
{"next": 21}
```

- `next` = номер последнего назначенного id (без префикса `e`).
- При создании нового: `id = e{(next+1):03}`, `codepoint = U+(E000 + next)`.
- **Не уменьшается** при удалении: удалённый кодпоинт не переиспользуется.
- При достижении 6400 — ошибка «Не осталось свободных PUA-кодпоинтов».

### 2.4. PUA-диапазон

`U+E000`…`U+F8FF` — 6400 кодпоинтов (Private Use Area, Unicode).

**Почему PUA:**

- не конфликтует с Unicode-эмодзи;
- один кодпоинт = один grapheme cluster = одна ячейка в сетке;
- работает в `Intl.Segmenter` (JS) и `grapheme_strlen` (PHP) как обычный символ;
- не требует изменения схемы легенды/уровня.

---

## 3. Пайплайн загрузки

### 3.1. Общий поток

```
Пользователь → [файл] → [MIME-валидация] → [формат-специфичная обработка] → [сохранение] → [JSON-запись] → [audit.log]
```

### 3.2. Валидация MIME

```php
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($tmpPath);
$allowed = [
    'image/png',
    'image/gif',
    'image/svg+xml',
    'text/xml',
    'application/xml',
];
if (!in_array($mime, $allowed, true)) {
    throw new UploadException('Допустимы только PNG, GIF, SVG');
}
if (filesize($tmpPath) > 200 * 1024) {
    throw new UploadException('Файл больше 200 KB');
}
```

`image/svg+xml` может определяться как `text/xml` или `application/xml` в зависимости от содержимого — все три принимаются, но **дальше идёт санитайзер**, который проверит корневой элемент.

### 3.3. Обработка PNG

```
1. getimagesize() → 8×8 … 256×256 (иначе отказ)
2. imagecreatefrompng() → ресурс GD
3. Ре-энкод в PNG БЕЗ изменения размеров — только очистка:
   - метаданных (EXIF, текстовых чанков);
   - полиглотов (данные после IEND);
   - вредоносного содержимого (PHP-код в чанках).
4. imagepng() → media/emoji-bank/{id}.png
5. Оригинал НЕ сохраняется
6. Освобождение GD-ресурсов
7. source_size = размеры исходного файла
```

**Размеры не меняются:** 32×32 на входе — 32×32 на выходе.

**Прозрачность:** сохраняется (`imagealphablending(false)`, `imagesavealpha(true)`).

**Ре-энкод — это безопасность, не эстетика.** Он гарантирует, что в файле нет посторонних данных.

### 3.4. Обработка GIF

```
1. getimagesize() → 8×8 … 256×256
2. imagecreatefromgif() → первый кадр
3. Дальше как PNG (ре-энкод в PNG, без изменения размеров)
4. imagepng() → media/emoji-bank/{id}.png
```

**Анимированный GIF не сохраняется** — только первый кадр. Анимация в банке — только через последовательность кадров (см. §5).

### 3.5. Обработка SVG

```
1. file_get_contents() → строка SVG
2. Проверка размера ≤ 200 KB (уже на этапе MIME)
3. GeometrySvgSanitizer::sanitize() → санитизированная строка
4. Проверка «есть хотя бы одна фигура» → иначе отказ
5. Проверка viewBox/width/height ≤ 512
6. saveXML() → media/emoji-bank/{id}.svg
7. sanitized_hash = 'sha256:' . hash('sha256', $sanitized)
8. Оригинал НЕ сохраняется
9. source_size = viewBox или width/height (или 64×64, если не заданы)
```

**SVG остаётся SVG** — не растеризуется, не масштабируется. Векторная масштабируемость сохраняется.

### 3.6. Назначение `id` и `codepoint`

```php
$counter = $store->read('emoji-bank-counter.json', ['next' => 0]);
if ($counter['next'] >= 6400) {
    throw new StorageException('Не осталось свободных PUA-кодпоинтов. Лимит — 6400.');
}
$idNum   = $counter['next'] + 1;
$id      = 'e' . str_pad($idNum, 3, '0', STR_PAD_LEFT);
$pua     = sprintf('U+%04X', 0xE000 + ($idNum - 1));
```

После успешного сохранения файла и записи в JSON — счётчик инкрементируется атомарно. При ошибке записи — счётчик **не** инкрементируется.

---

## 4. Санитизация SVG (`GeometrySvgSanitizer`)

### 4.1. Белый список элементов

| Элемент | Назначение |
|---|---|
| `svg` | Корневой контейнер |
| `g` | Группировка |
| `defs` | Контейнер градиентов |
| `linearGradient`, `radialGradient`, `stop` | Градиенты |
| `rect`, `circle`, `ellipse`, `line`, `polyline`, `polygon`, `path` | Геометрия |

**Запрещено (удаляется с содержимым):** `text`, `tspan`, `image`, `use`, `foreignObject`, `script`, `style`, `animate*`, `set`, `filter`, `clipPath`, `mask`, `pattern`, `marker`, `symbol`, `iframe`, `audio`, `video`.

### 4.2. Белый список атрибутов

| Группа | Атрибуты |
|---|---|
| `svg` | `viewBox`, `width`, `height`, `xmlns` |
| Геометрия | `x`, `y`, `width`, `height`, `rx`, `ry`, `cx`, `cy`, `r`, `x1`, `y1`, `x2`, `y2`, `points`, `d` |
| Трансформация | `transform` (только `matrix`/`translate`/`scale`/`rotate`/`skewX`/`skewY`) |
| Цвета | `fill`, `stroke`, `stop-color` — `none` \| `#RGB` \| `#RRGGBB` \| `#RGBA` \| `#RRGGBBAA` \| `rgb()` \| `rgba()` \| `hsl()` \| `hsla()` \| именованные CSS \| `url(#id)` |
| Прозрачность | `opacity`, `fill-opacity`, `stroke-opacity`, `stop-opacity` — 0…1 |
| Обводка | `stroke-width`, `stroke-linecap` (`butt`/`round`/`square`), `stroke-linejoin` (`miter`/`round`/`bevel`), `stroke-dasharray`, `stroke-dashoffset`, `fill-rule` (`nonzero`/`evenodd`), `clip-rule` |
| Градиенты | `x1`, `y1`, `x2`, `y2`, `cx`, `cy`, `r`, `fx`, `fy`, `gradientUnits` (`objectBoundingBox`/`userSpaceOnUse`), `gradientTransform`, `spreadMethod` (`pad`/`reflect`/`repeat`) |
| `stop` | `offset`, `stop-color`, `stop-opacity` |
| `id` | `^[a-zA-Z_][a-zA-Z0-9_-]{0,63}$` |

**Запрещено:** `on*`, `xlink:*`, `href`, `class`, `src`, `xml:base`, `currentColor` (только конкретные цвета).

### 4.3. Проверка связности `url(#id)`

1. Собираются все `id` градиентов (`linearGradient`, `radialGradient`).
2. Все `url(#id)` в `fill`/`stroke`/`stop-color` сверяются с этим списком.
3. При несовпадении — атрибут заменяется на `none`.

### 4.4. Защита от XXE

- `libxml_disable_entity_loader(true)`.
- `LIBXML_NONET`.
- Явная проверка строки на `<!DOCTYPE` и `<!ENTITY` — при обнаружении отклонение.

### 4.5. Проверка «есть фигура»

После санитизации должна остаться хотя бы одна фигура (`path`/`rect`/`circle`/`ellipse`/`polygon`/`polyline`/`line`). Иначе — отказ: «SVG не содержит геометрии».

### 4.6. Сериализация

- `DOMDocument::saveXML($root)` без XML declaration.
- Без комментариев, без текстовых узлов, без CDATA.
- `sanitized_hash = 'sha256:' . hash('sha256', $sanitized)` — для аудита.

---

## 5. Анимации

### 5.1. Принцип

Анимация — **запись в банке**, ссылающаяся на последовательность существующих **статичных** эмодзи как на кадры. Отдельного файла нет. Анимация получает свой PUA-кодпоинт и используется в легендах/уровнях как обычный эмодзи.

### 5.2. Схема `frames[]`

```json
[
  {"codepoint": "U+E000", "duration_ms": 150},
  {"codepoint": "U+E001", "duration_ms": 150},
  {"codepoint": "U+E002", "duration_ms": 150},
  {"codepoint": "U+E001", "duration_ms": 150}
]
```

| Поле | Правило |
|---|---|
| `frames.length` | 2–20 |
| `frames[].codepoint` | Существует в банке, имеет `type: "static"` (запрет вложенности) |
| `frames[].duration_ms` | 50–2000 |
| Сумма `duration_ms` | ≤ 10 000 |
| Один и тот же кадр может встречаться несколько раз | Разрешено |

### 5.3. `loop_mode`

| Значение | Поведение |
|---|---|
| `loop` | Бесконечно повторяется |
| `once` | Один раз, замирает на последнем кадре |
| `ping_pong` | Вперёд-назад (A→B→C→B→A→B→C→…) |

### 5.4. Создание анимации

Форма в админке:

```
┌─ Новая анимация ────────────────────────────────────────┐
│                                                          │
│  Название:  [Рыцарь идёт____________________]           │
│  Категория: [Персонажи ▼]                               │
│                                                          │
│  ── Кадры ──                                            │
│  ┌────────────────────────────────────────────────┐    │
│  │ ≡ [img] U+E000  Рыцарь 1  [150] мс  [✕]        │    │
│  │ ≡ [img] U+E001  Рыцарь 2  [150] мс  [✕]        │    │
│  │ ≡ [img] U+E002  Рыцарь 3  [150] мс  [✕]        │    │
│  │ ≡ [img] U+E001  Рыцарь 2  [150] мс  [✕]        │    │
│  └────────────────────────────────────────────────┘    │
│  [+ Добавить кадр из банка]                             │
│                                                          │
│  ── Режим ──                                            │
│  (•) Цикл  ( ) Один раз  ( ) Пинг-понг                  │
│                                                          │
│  ── Просмотр ──                                         │
│  ┌──────────┐                                           │
│  │  [img]   │  ▶ Проиграть  ⏸ Пауза                   │
│  └──────────┘  Итого: 600 мс, 4 кадра                  │
│                                                          │
│  [Отмена]                          [Сохранить]          │
└──────────────────────────────────────────────────────────┘
```

**Модалка «Добавить кадр из банка»:**

- Список **только статичных** эмодзи (анимации исключены).
- Фильтр по категории, поиск.
- Превью.
- Клик — добавление в конец списка кадров.

**Валидация в реальном времени:**

- Минимум 2 кадра.
- Каждая длительность 50–2000.
- Сумма ≤ 10 000.
- Все кадры — `type: "static"`.

### 5.5. Отображение анимаций

**В списке банка:**

```
┌──────────────┐
│  ▶ [img]     │  ← значок «▶» поверх первого кадра
│ e020         │
│ Рыцарь идёт  │
│ U+E013       │
│ ANIM 4×150ms │
│ [✎] [🗑]     │
└──────────────┘
```

**При наведении на карточку** — анимация автоматически проигрывается в миниатюре.

**Фильтр «Тип»** — «Все», «Статичные», «Анимации».

### 5.6. Замена изображения кадра

Если статичный эмодзи используется в `frames[]` любой анимации — **замена разрешена**; все анимации автоматически видят новый образ. Это удобно для итеративной работы.

### 5.7. Запрет вложенности

При выборе кадров в модалке — только `type: "static"`. Прямая подделка POST с `frame.codepoint`, указывающим на анимацию, — ошибка валидации «Кадр должен быть статичным эмодзи».

---

## 6. UI: раздел «Банк эмодзи» (`/admin/emoji-bank`)

### 6.1. Список

```
┌─ Банк эмодзи ───────────────────────────────────────────────────┐
│  [+ Загрузить эмодзи] [▶ Создать анимацию]                    │
│  [Экспорт ZIP] [Импорт ZIP]                                    │
├─────────────────────────────────────────────────────────────────┤
│  Фильтр:                                                       │
│    Категория: [Все] [Персонажи] [Оружие] [Природа] [UI]       │
│                [Символы] [Другое]                              │
│    Тип:       [Все] [Статичные] [Анимации]                    │
│  Поиск: [____________________________________]                 │
├─────────────────────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐           │
│  │  [img]   │ │  [img]   │ │  [img]   │ │  [img]   │           │
│  │ e001     │ │ e002     │ │ e003     │ │ e020 ▶   │           │
│  │ Рыцарь   │ │ Меч      │ │ Щит      │ │ Анимация │           │
│  │ U+E000   │ │ U+E001   │ │ U+E002   │ │ U+E013   │           │
│  │ PNG      │ │ SVG      │ │ SVG      │ │ ANIM     │           │
│  │ 32×32    │ │ 64×64    │ │ 64×64    │ │ 4×150ms  │           │
│  │ [✎] [🗑] │ │ [✎] [🗑] │ │ [✎] [🗑] │ │ [✎] [🗑] │           │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘           │
│                                                                 │
│  Показано: 4 из 42                                              │
└─────────────────────────────────────────────────────────────────┘
```

**Карточка эмодзи:**

- Превью (натуральный размер, максимум 96×96 в UI).
- `id` и `codepoint`.
- `name`.
- Бейдж формата: `PNG` / `GIF` / `SVG` / `ANIM`.
- `source_size` (для static).
- Кнопки ✎ (редактировать) и 🗑 (удалить).

**Фильтры:** по 6 категориям, по типу (static/animation), поиск по `name`/`codepoint`.

**Пагинация:** 48 на страницу (6×8).

**Пустое состояние:** «Банк пуст. Загрузите первый эмодзи — PNG, GIF или SVG. Или создайте анимацию из существующих кадров.»

### 6.2. Форма загрузки (модалка)

```
┌─ Загрузка эмодзи ─────────────────────────────────────┐
│                                                        │
│  ┌──────────────────────────────────────────────┐     │
│  │  Перетащите файл сюда или нажмите             │     │
│  │  PNG, GIF, SVG — до 200 KB                    │     │
│  │  Размеры: 8×8 … 256×256                       │     │
│  └──────────────────────────────────────────────┘     │
│                                                        │
│  Название:  [Пиксельный рыцарь_________________]      │
│  Категория: [Персонажи ▼]                              │
│                                                        │
│  Предпросмотр:                                         │
│  ┌──────────┐                                          │
│  │  [img]   │  ← отрисовка через <img>                 │
│  └──────────┘                                          │
│                                                        │
│  [Отмена]                              [Загрузить]    │
└────────────────────────────────────────────────────────┘
```

**Логика:**

- При выборе файла — клиент отправляет его на `POST /admin/emoji-bank/preview` (без сохранения), получает обработанную версию (ре-энкод PNG или санитизированный SVG) и показывает превью через `<img>`.
- Если валидация/санитизация не удалась — ошибка под dropzone, кнопка «Загрузить» заблокирована.
- После «Загрузить» — карточка появляется в списке, flash «Эмодзи „X" загружен (U+E000)».

**Отображение предпросмотра:**

- Для PNG/GIF — прямая ссылка на результат.
- Для SVG — `<img src="data:image/svg+xml;base64,...">` (встроенный data-URL; инлайн-вставка SVG в DOM **не делается** — только через `<img>`).

### 6.3. Форма редактирования метаданных

Модалка:

- `name` — редактируется.
- `category` — редактируется.
- **Для static:** кнопка «Заменить изображение» — открывает dropzone; при загрузке нового файла перезаписывается `image_path`, обновляется `sanitized_hash` (для SVG), обновляется `source_format`/`stored_format`/`source_size`, `codepoint` и `id` сохраняются.
- **Для animation:** редактирование `frames[]` и `loop_mode` через форму анимации.
- `id`, `codepoint`, `source_format`, `stored_format`, `image_path`, `sanitized_hash`, `created_at` — read-only.

### 6.4. Удаление

Модалка:

- Ввод `name` для подтверждения.
- Предупреждение: «Эмодзи „X" будет удалён из банка. Если он используется в легендах, уровнях или анимациях — удаление будет заблокировано.»
- Проверка использования:
  - `legend[codepoint]` в `data/games/*.json`;
  - `levels[].grid` в `data/games/*.json`;
  - `default_legend[codepoint]` в `data/genres.json`;
  - `frames[].codepoint` в `data/emoji-bank.json` (для static).
- Если используется — блокировка с перечислением (игр / уровней / жанров / анимаций).
- Иначе — файл удаляется, запись удаляется, `audit.log`.

**Кодпоинт не переиспользуется.** Счётчик не уменьшается.

### 6.5. Экспорт/импорт ZIP

**Экспорт:**

- Кнопка «Экспорт ZIP» скачивает `emoji-bank-YYYYMMDD-HHMM.zip`.
- Содержимое: `emoji-bank.json`, `emoji-bank-counter.json`, `files/*.{png,svg}`.
- Анимации в ZIP не имеют отдельных файлов (ссылаются на `codepoint` кадров).

**Импорт:**

- Кнопка «Импорт ZIP» — загрузка файла ≤ 20 MB.
- Валидация:
  - `emoji-bank.json` присутствует, схема валидна;
  - все `id` соответствуют `^e\d{3,}$`;
  - все `codepoint` в PUA-диапазоне и уникальны;
  - нет конфликтов `id` с существующими;
  - нет конфликтов `codepoint` с существующими;
  - файлы в ZIP соответствуют `image_path` каждой записи;
  - каждый файл проходит MIME-валидацию (SVG — повторная санитизация).
- **Двухпроходный импорт:**
  1. Все статичные эмодзи проверяются и добавляются.
  2. Анимации проверяются: все `frame.codepoint` должны существовать после шага 1.
- **Счётчик:** обновляется до `max(id_num существующих) + 1`.
- **Атомарность:** сначала все проверки, затем запись; при любой ошибке — откат (файлы не записаны, JSON не изменён).
- **Не перезаписывает** существующие записи — только добавляет новые.

---

## 7. Публичный эндпоинт `/api/emoji-bank.json`

### 7.1. Формат

```json
{
  "U+E000": {
    "type": "static",
    "url": "/media/emoji-bank/e001.png"
  },
  "U+E001": {
    "type": "static",
    "url": "/media/emoji-bank/e002.svg"
  },
  "U+E013": {
    "type": "animation",
    "loop_mode": "loop",
    "frames": [
      {"url": "/media/emoji-bank/e001.png", "duration_ms": 150},
      {"url": "/media/emoji-bank/e002.svg", "duration_ms": 150},
      {"url": "/media/emoji-bank/e003.png", "duration_ms": 150},
      {"url": "/media/emoji-bank/e002.svg", "duration_ms": 150}
    ],
    "total_duration_ms": 600
  }
}
```

**Отдаётся только `{codepoint: {type, url/frames}}`** — без `id`, `name`, `category`, `sanitized_hash`, `source_size`.

### 7.2. Кэширование

- `Cache-Control: public, max-age=3600`.
- `ETag` по sha256 содержимого файла.
- `Last-Modified` — время последнего изменения `emoji-bank.json`.

### 7.3. Доступ

- Без авторизации.
- Rate-limit: **120/мин на IP** (см. блок 3).

### 7.4. Использование в браузере

```js
// boot.js (блок 11)
const bank = await fetch('/api/emoji-bank.json').then(r => r.json());
const bankMap = new Map();

const loadPromises = Object.entries(bank).map(([codepoint, entry]) => {
  if (entry.type === 'static') {
    return loadImage(entry.url).then(img => {
      bankMap.set(codepoint, { type: 'static', image: img });
    });
  }
  const framePromises = entry.frames.map(f =>
    loadImage(f.url).then(img => ({ image: img, duration_ms: f.duration_ms }))
  );
  return Promise.all(framePromises).then(frames => {
    bankMap.set(codepoint, {
      type: 'animation',
      frames,
      loop_mode: entry.loop_mode,
      total_duration_ms: entry.total_duration_ms,
    });
  });
});

await Promise.all(loadPromises);
// bankMap готов к использованию в движке
```

**Предзагрузка до старта игрового цикла** — иначе первые кадры будут без картинок.

---

## 8. Валидация

### 8.1. При загрузке PNG/GIF

| Правило | Проверка |
|---|---|
| MIME ∈ `{image/png, image/gif}` | `finfo_file` |
| Размер файла ≤ 200 KB | `filesize` |
| Размеры 8×8 … 256×256 | `getimagesize` до создания GD-ресурса |
| `imagecreatefrom*` вернул ресурс | Проверка на `false` |
| `imagepng` вернул `true` | Проверка |

### 8.2. При загрузке SVG

| Правило | Проверка |
|---|---|
| MIME ∈ `{image/svg+xml, text/xml, application/xml}` | `finfo_file` |
| Размер файла ≤ 200 KB | `filesize` |
| Нет `<!DOCTYPE`/`<!ENTITY` | Явная проверка |
| Корневой элемент `<svg>` | `DOMDocument` |
| Есть хотя бы одна фигура после санитизации | Проверка |
| `viewBox`/`width`/`height` ≤ 512 | Проверка |
| Все `url(#id)` ссылаются на существующие градиенты | Проверка |

### 8.3. При создании анимации

| Правило | Проверка |
|---|---|
| `name` 2–80 | `mb_strlen` |
| `category` ∈ 6 | Enum |
| `frames.length` 2–20 | `count` |
| Каждый `frames[].codepoint` существует | Поиск в `emoji-bank.json` |
| Каждый `frames[].codepoint` имеет `type: "static"` | Проверка |
| `duration_ms` 50–2000 | Числовое |
| Сумма ≤ 10 000 | Числовое |
| `loop_mode` ∈ `{loop, once, ping_pong}` | Enum |
| Свободный PUA-кодпоинт | Счётчик < 6400 |

### 8.4. При удалении

Блокировка при использовании в:

- `legend[codepoint]` в `data/games/*.json`;
- `levels[].grid` в `data/games/*.json`;
- `default_legend[codepoint]` в `data/genres.json`;
- `frames[].codepoint` в `data/emoji-bank.json` (для static).

### 8.5. При импорте ZIP

| Правило | Проверка |
|---|---|
| MIME ∈ `{application/zip, application/x-zip-compressed}` | `finfo_file` |
| Размер ≤ 20 MB | `filesize` |
| Структура `emoji-bank.json`, `emoji-bank-counter.json`, `files/*` | Проверка |
| **Zip-slip:** все пути в архиве соответствуют `files/{id}.{png\|svg}` | Проверка |
| Схема `emoji-bank.json` валидна | Проверка |
| Все `id` соответствуют `^e\d{3,}$`, уникальны | Проверка |
| Все `codepoint` в PUA-диапазоне, уникальны | Проверка |
| Нет конфликтов `id`/`codepoint` с существующими | Проверка |
| Каждый файл проходит MIME-валидацию | `finfo_file` |
| Каждый SVG повторно санитизируется | `GeometrySvgSanitizer` |
| Распакованный размер ≤ 100 MB | `ZipArchive::statIndex` |

---

## 9. Безопасность

| Угроза | Мера |
|---|---|
| XSS через SVG | Санитизация до геометрии + градиентов; рендер через `<img>`; CSP `default-src 'none'; img-src data:` |
| XXE | `libxml_disable_entity_loader(true)`; `DOCTYPE`/`ENTITY` отклоняются |
| Decompression bomb | `getimagesize` до создания GD-ресурса; лимит 256×256 |
| PNG-полиглот | Ре-энкод через GD; оригинал не сохраняется |
| GIF-бомба | Только первый кадр; ре-энкод |
| Открытие SVG напрямую | `Content-Type: image/svg+xml`; `X-Content-Type-Options: nosniff`; `X-Frame-Options: DENY`; CSP |
| Path traversal через `{id}` | Regex `^e\d{3,}$`; проверка существования |
| Подмена `codepoint` | Сервер назначает; клиент не может |
| XSS в `name` | `htmlspecialchars()` при выводе |
| CSRF | Токен из блока 3 |
| Zip-slip | Проверка путей в архиве |
| Огромный ZIP | Лимит 20 MB; распакованный — 100 MB |
| Переиспользование кодпоинта | Счётчик не уменьшается |
| Исполнение PHP в `/media/emoji-bank/` | `php_flag engine off`; `RemoveHandler`; nginx `deny` |

### 9.1. Изоляция `/public/media/emoji-bank/`

**Apache (`.htaccess`):**

```apache
<FilesMatch "\.svg$">
    ForceType image/svg+xml
    Header always set Content-Security-Policy "default-src 'none'; img-src data:;"
    Header always set X-Content-Type-Options "nosniff"
    Header always set X-Frame-Options "DENY"
</FilesMatch>
php_flag engine off
RemoveHandler .php .phtml .sh .html .htm
```

**Nginx:**

```nginx
location /media/emoji-bank/ {
    types { image/svg+xml svg; }
    add_header Content-Security-Policy "default-src 'none'; img-src data:;" always;
    add_header X-Content-Type-Options "nosniff" always;
    add_header X-Frame-Options "DENY" always;
    location ~ \.(php|phtml|sh|html|htm)$ { deny all; }
}
```

---

## 10. Аудит

```json
{"ts":"...","user":"admin","action":"emoji_bank.upload","target":"e001","details":{"format":"png","bytes":12345,"source_size":{"width":32,"height":32},"codepoint":"U+E000"}}
{"ts":"...","user":"admin","action":"emoji_bank.upload","target":"e002","details":{"format":"svg","bytes":2345,"sanitized_bytes":1890,"codepoint":"U+E001","removed_elements":["text","linearGradient"]}}
{"ts":"...","user":"admin","action":"emoji_bank.upload_rejected","details":{"format":"svg","reason":"no_geometry","ip":"1.2.3.4"}}
{"ts":"...","user":"admin","action":"emoji_bank.animation_create","target":"e020","details":{"codepoint":"U+E013","frames":["U+E000","U+E001","U+E002","U+E001"],"loop_mode":"loop","total_duration_ms":600}}
{"ts":"...","user":"admin","action":"emoji_bank.animation_update","target":"e020","details":{"changed":["frames","loop_mode"]}}
{"ts":"...","user":"admin","action":"emoji_bank.update","target":"e001","details":{"changed":["name","category"]}}
{"ts":"...","user":"admin","action":"emoji_bank.delete","target":"e003"}
{"ts":"...","user":"admin","action":"emoji_bank.delete_blocked","target":"e002","details":{"used_in_games":["game-001"],"used_in_levels":["game-001/level-002"],"used_in_animations":["e020","e025"]}}
```

Поле `removed_elements` — **для администратора** (полезно понимать, что вычищено). В UI пользователю показывается только общее сообщение.

---

## 11. Критерии приёмки блока 6

### 11.1. Загрузка PNG

1. **Загрузка PNG 32×32** → создаётся `media/emoji-bank/e001.png` размером **32×32** (без изменения размеров), запись в JSON с `codepoint: "U+E000"`, `source_size: {width: 32, height: 32}`.
2. **Загрузка PNG 128×128** → сохраняется как 128×128.
3. **Превышение размера** (>256×256) → ошибка.
4. **Слишком маленький** (<8×8) → ошибка.
5. **Файл > 200 KB** → ошибка.
6. **Не-image файл (PDF)** → ошибка «Допустимы только PNG, GIF, SVG».
7. **PNG с метаданными** — ре-энкод удаляет метаданные; `source_size` сохраняется.

### 11.2. Загрузка GIF

8. **GIF** → первый кадр извлекается, конвертируется в PNG, сохраняется **без изменения размеров**.
9. **Анимированный GIF** → сохраняется только первый кадр.

### 11.3. Загрузка SVG

10. **SVG с `rect` + `path` + `linearGradient`** → санитизируется (геометрия и градиент остаются), сохраняется как `e00X.svg`, `sanitized_hash` заполнен.
11. **SVG с `<text>` и `<script>`** → `<text>` и `<script>` удаляются, остальная геометрия сохраняется.
12. **SVG без фигур** → ошибка «SVG не содержит геометрии».
13. **SVG с `url(#missing)`** → атрибут удаляется, `fill` становится `none`.
14. **SVG с `<!DOCTYPE`** → отклоняется.
15. **SVG `viewBox` 1024×1024** → ошибка.

### 11.4. PUA-кодпоинты

16. **Первый эмодзи** — `U+E000`; второй — `U+E001`; и так далее без пропусков.
17. **После удаления третьего** — четвёртый получает `U+E003`, а не `U+E002`.
18. **Исчерпание диапазона** (6401-й эмодзи) → ошибка «Не осталось свободных PUA-кодпоинтов».

### 11.5. Анимации

19. **Создание анимации** с 3 кадрами — валидно; в JSON `type: "animation"`, `frames[]` заполнен.
20. **Менее 2 кадров** — ошибка.
21. **Сумма > 10 000 мс** — ошибка.
22. **Ссылка на анимацию как кадр** — ошибка «Кадр должен быть статичным эмодзи».
23. **Дубликат кадра разрешён** — `[A, B, A]` валидно.
24. **Просмотр** — canvas проигрывает анимацию с заданными длительностями и `loop_mode`.
25. **`ping_pong`** — анимация проигрывается вперёд-назад.
26. **Список** — карточка анимации с «▶» и первым кадром; hover — проигрывание.

### 11.6. UI

27. **Список** — все эмодзи с превью, `id`, `codepoint`, `name`, форматом, `source_size`; фильтры и поиск работают.
28. **Форма загрузки** — предпросмотр через `<img>` после выбора файла (без сохранения); опций пикселизации/ресемплинга/fit-режима **нет**.
29. **Редактирование метаданных** — `name`, `category`; кнопка «Заменить изображение» для static; `id`/`codepoint` — read-only.
30. **Удаление свободного** — успешно; кодпоинт не переиспользуется.
31. **Удаление используемого** — блокировка с перечнем игр/уровней/анимаций.

### 11.7. Публичный эндпоинт

32. **`GET /api/emoji-bank.json`** — возвращает `{codepoint: {type, url/frames}}` без лишних полей.
33. **Кэширование** — `Cache-Control: public, max-age=3600`.
34. **Файлы доступны** — `/media/emoji-bank/e001.png` и `.svg` отдаются с правильными MIME и защитными заголовками.
35. **Rate-limit** — 121-й запрос за 60 сек → 429.

### 11.8. Экспорт/импорт ZIP

36. **Экспорт** — скачивает ZIP с `emoji-bank.json`, `emoji-bank-counter.json`, `files/`.
37. **Импорт в пустую систему** — все записи добавляются.
38. **Импорт с конфликтом `id`** — ошибка, ничего не записано.
39. **Импорт с анимациями** — кадры должны существовать после шага статики.
40. **Zip-slip** — ZIP с `../evil.php` отклоняется.

### 11.9. Безопасность и аудит

41. **CSRF** — POST без токена — 403.
42. **Прямой доступ к `/data/emoji-bank.json`** — 403/404.
43. **XSS в `name`** — экранировано при выводе.
44. **Аудит** — все операции записаны в `audit.log`.

### 11.10. Обратная совместимость

45. **Критерии блоков 1–5, 10** выполняются.

---

## 12. Оценка

**Ориентир:** 2,5–3 человеко-дня на разработчика (или ~1 рабочий день с ИИ-ассистентом).

Основное время:

- **`GeometrySvgSanitizer`** — самый ответственный компонент: белый список, проверка `url(#id)`, отсечение XXE, тесты (≈1 день).
- **Обработка PNG/GIF** — ре-энкод без изменения размеров (≈0,3 дня).
- **PUA-счётчик** — с защитой от переиспользования (≈0,2 дня).
- **UI** — список с фильтрами, форма загрузки, превью, редактирование, удаление с проверкой использования, форма анимации (≈0,8 дня).
- **Экспорт/импорт ZIP** — с валидацией и транзакционностью (≈0,4 дня).
- **Публичный эндпоинт** с кэшированием (≈0,2 дня).
- **Отладка и тестирование** (≈0,4 дня).

---

## 13. Что не делаем в блоке 6 и почему

- **Не пикселизируем PNG/GIF** — эстетика, не механика. Как спрайт выглядит на экране — определяется `tile_size` и CSS `image-rendering` в движке (блок 11).
- **Не ресемплируем** — размеры сохраняются как в исходнике.
- **Не приводим к единому размеру** (64×64 или иному) — нет технической необходимости; движок масштабирует под клетку.
- **Не реализуем fit-режимы** (letterbox/stretch/crop) — эстетика.
- **Не растеризуем SVG** — векторная масштабируемость сохраняется.
- **Не реализуем SVG-анимацию** (`<animate>`) — только статичные SVG; для анимации — последовательность кадров.
- **Не реализуем GIF-анимацию** — только первый кадр.
- **Не реализуем вложенные анимации** (кадр-анимация) — запрет валидации.
- **Не реализуем редактор SVG** — загрузка готового файла.
- **Не реализуем кастомные категории** — 6 фиксированных.
- **Не реализуем массовую загрузку** — только по одной. Bulk — опционально в блоке 12.
- **Не реализуем публичную галерею банка** — эндпоинт отдаёт только `{codepoint: url/frames}`.
- **Не реализуем генерацию превью** — превью — сам файл; SVG масштабируется `<img>`.

---

**Конец блока 6 из 13.**

Следующий блок — **Карточка игры и легенда** (пять вкладок карточки, обязательные поля, обложка, категории, статус, легенда: 9 ролей, композиты 1×1…3×2, действия, `behavior_mode`, импорт из жанра, emoji-picker с вкладкой «Банк»).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 7. Карточка игры и легенда

## 1. Общее описание

Карточка игры — **центральная сущность CMS**. Содержит метаданные игры, легенду (emoji → свойства), уровни, управление, звуки и настройки движка.

**Пять вкладок** в единой форме:

| Вкладка | Содержимое |
|---|---|
| **Основное** | `title`, `slug`, `genre_id`, `description`, `cover`, `category_ids`, `status`, `meta.seo_*` |
| **Легенда** | Emoji-элементы игры: роли, свойства, действия, `behavior_mode`, композиты, боевые свойства |
| **Уровни** | Emoji-сетка, визуальный редактор, триггеры, условия победы/поражения |
| **Управление** | Назначение клавиш по действиям движка |
| **Настройки** | Параметры движка, атаки игрока, до 10 бипер-звуков |

**Единая кнопка «Сохранить»** внизу страницы — атомарное сохранение всех вкладок в один JSON.

**Наследование при создании игры:**

- `controls = genre.default_controls`;
- `sounds = genre.default_sounds`;
- `engine_overrides = {}`;
- `legend = {}`;
- `levels = []`.

Если `controls`/`sounds` в игре пусты — при чтении используются дефолты жанра.

---

## 2. Схема `games/{game-id}.json`

```json
{
  "id": "game-001",
  "slug": "zoloto-bungelingov",
  "title": "Золото Бунгелингов",
  "genre_id": "genre-loderunner",
  "description": "Одноэкранная головоломка: соберите всё золото и уйдите через выход.",
  "cover": {
    "path": "media/game-001/cover.webp",
    "width": 640,
    "height": 480,
    "alt": "Обложка: пещера с золотом",
    "uploaded_at": "2026-10-21T10:00:00Z"
  },
  "category_ids": ["cat-puzzle", "cat-arcade"],
  "status": "published",
  "controls": {
    "left": ["ArrowLeft", "KeyA"],
    "right": ["ArrowRight", "KeyD"],
    "up": ["ArrowUp", "KeyW"],
    "down": ["ArrowDown", "KeyS"],
    "dig_left": ["KeyZ"],
    "dig_right": ["KeyX"],
    "pause": ["Escape"],
    "start": ["Enter"]
  },
  "sounds": [
    {"event": "dig",    "wave": "square", "frequency_hz": 440,  "duration_ms": 50, "volume": 0.03, "envelope": "decay"},
    {"event": "pickup", "wave": "square", "frequency_hz": 1320, "duration_ms": 60, "volume": 0.04, "envelope": "pluck"}
  ],
  "engine_overrides": {
    "speed_scale": 1.1,
    "sprite_flicker": true,
    "sprite_flicker_intensity": 0.12,
    "input_responsiveness": 0.9,
    "sound_volume": 0.04
  },
  "legend": {
    "🧱": {
      "role": "obstacle",
      "name": "Кирпич",
      "solid": true,
      "diggable": true
    },
    "🏃": {
      "role": "start",
      "name": "Игрок"
    },
    "💰": {
      "role": "pickup",
      "name": "Золото",
      "pickup_id": "gold",
      "effect": "score",
      "value": 10
    },
    "🚪": {
      "role": "exit",
      "name": "Выход",
      "mode": "after_all"
    },
    "🤖": {
      "role": "mob",
      "name": "Робот",
      "hp": 3,
      "damage_on_touch": 1,
      "behavior_mode": "ai",
      "ai_model": "patrol_horizontal",
      "ai_params": {"speed": 0.4, "range": 5, "turn_on_wall": true, "turn_on_edge": true},
      "movement_pattern": null,
      "movement_params": {},
      "armor": 0,
      "knockback_resistance": 0.0,
      "stun_resistance": 0.0,
      "weak_point": null,
      "shield_front": null,
      "drop_emoji": "💰",
      "score_value": 100,
      "explosion_on_death": null,
      "actions": []
    }
  },
  "levels": [
    {
      "level_id": "level-001",
      "name": "Вход в шахту",
      "order": 1,
      "grid": [
        "🧱 🧱 🧱 ... 🧱",
        "..."
      ],
      "thumb": "media/game-001/level-001-thumb.webp",
      "params": {
        "time_limit_seconds": 120,
        "triggers": [],
        "victory_conditions": [{"type": "reach_exit"}],
        "defeat_conditions": [{"type": "hp_zero"}, {"type": "lives_zero"}]
      },
      "created_at": "2026-10-21T15:00:00Z",
      "updated_at": "2026-10-21T15:30:00Z"
    }
  ],
  "meta": {
    "seo_title": "",
    "seo_description": ""
  },
  "created_at": "2026-10-21T10:00:00Z",
  "updated_at": "2026-10-21T12:30:00Z"
}
```

### 2.1. Правила полей

| Поле | Обязательно | Правило |
|---|---|---|
| `id` | ✅ | `^game-\d{3,}$`, иммутабелен |
| `slug` | ✅ | `^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$`, уникален; read-only после сохранения |
| `title` | ✅ | 1–120 |
| `genre_id` | ✅ | существующий `genre.id` |
| `description` | ✅ | 20–1000 |
| `cover.path` | ✅ | файл существует в `media/{game-id}/` |
| `category_ids` | ✅ | массив `cat.*`, без дубликатов |
| `status` | ✅ | `draft` \| `published` |
| `controls` | ✅ | все обязательные действия движка назначены |
| `sounds` | ✅ | ≤ 10 событий, уникальные |
| `engine_overrides` | ❌ | по схеме движка |
| `legend` | ❌ | по схеме легенды (§3) |
| `levels` | ❌ | массив уровней; для публикации ≥ 1 |
| `meta` | ❌ | `seo_title`, `seo_description` |

**Публикация (`status: published`) требует:** `cover` существует, `levels.length ≥ 1`, все обязательные действия в `controls`.

---

## 3. Легенда игры

### 3.1. Итоговая легенда при рендере

```
effective_legend = {...genre.default_legend, ...game.legend}
```

- Все emoji из `default_legend` жанра **наследуются** в игру, если не переопределены.
- Emoji, определённые в `game.legend`, **переопределяют** дефолтные.
- Игра может **добавлять** emoji, отсутствующие в жанре.

**Пример:** если в `genre.default_legend` есть `🧱` (кирпич), а в `game.legend` есть `🧱` (бетон — переопределение) — в игре будет бетон. Если в `game.legend` есть новый `🤖` — он добавляется к унаследованным.

### 3.2. Девять ролей

| Роль | Назначение | Примеры |
|---|---|---|
| `background` | Фон / пол | Трава, земля, вода |
| `obstacle` | Преграда (твёрдая или проходимая особым образом) | Кирпич, бетон, лестница, перекладина |
| `collider` | Активный объект (дверь, сундук, турель) | Замок, спавнер, ловушка |
| `mob` | Враг | Робот, солдат, призрак, босс |
| `pickup` | Предмет | Золото, ключ, зелье |
| `exit` | Выход с уровня | Дверь, флаг, лестница |
| `start` | Старт игрока | Один на уровень |
| `projectile` | Шаблон снаряда | Стрела, пуля, файербол |
| `decor` | Декор (без физики) | Куст, надпись, камень |

**Правила:**

- Ровно **один `start`** в эффективной легенде.
- `pickup.pickup_id` **уникален** в эффективной легенде.
- `projectile` не размещается в сетке напрямую — используется как ссылка из `player_attacks.ranged.projectile_emoji` и `actions[].shoot.projectile`.
- `decor` не имеет физики и логики (только визуал).

### 3.3. Схема элемента легенды

**Общие поля (все роли):**

| Поле | Тип | Правило |
|---|---|---|
| `role` | enum | 9 значений |
| `name` | string | 2–80 |
| `description` | string | 0–300, опционально |
| `composite` | object\|null | см. §3.4 |
| `solid` | bool | блокирует движение |
| `walkable` | bool | можно стоять сверху |
| `damage_on_touch` | number | 0–10, урон при касании |

**Специфичные поля по ролям:**

#### `background`

| Поле | Тип | Default | Правило |
|---|---|---|---|
| `walkable` | bool | `true` | обязательно `true` |

#### `obstacle`

| Поле | Тип | Default | Правило |
|---|---|---|---|
| `solid` | bool | `true` | обязательно `true` |
| `diggable` | bool | `false` | можно копать (Lode Runner) |
| `climbable` | bool | `false` | можно лазать (лестница) |
| `hangable` | bool | `false` | можно висеть снизу (перекладина) |
| `destructible` | bool | `false` | можно разрушить |
| `blocks_projectiles` | bool | `true` | останавливает снаряды |
| `walkable` | bool | `false` | — |

#### `collider`

| Поле | Тип | Default | Правило |
|---|---|---|---|
| `solid` | bool | `true` | обязательно `true` |
| `blocks_projectiles` | bool | `true` | — |
| `damage_on_touch` | number | 0 | 0–10 |
| `actions[]` | array | `[]` | см. §3.5 |

#### `mob`

| Поле | Тип | Default | Правило |
|---|---|---|---|
| `hp` | number | 1 | 1–99 |
| `damage_on_touch` | number | 1 | 0–10 |
| `behavior_mode` | enum | `ai` | `ai` \| `trajectory` \| `hybrid` |
| `ai_model` | string\|null | null | если `behavior_mode ∈ {ai, hybrid}` |
| `ai_params` | object | `{}` | по схеме модели |
| `movement_pattern` | string\|null | null | если `behavior_mode ∈ {trajectory, hybrid}` |
| `movement_params` | object | `{}` | по схеме паттерна |
| `formation` | object\|null | null | `vertical-scroll` — см. §3.6 |
| `armor` | number | 0 | 0–10 |
| `knockback_resistance` | number | 0.0 | 0.0–1.0 |
| `stun_resistance` | number | 0.0 | 0.0–1.0 |
| `weak_point` | object\|null | null | см. §3.7 |
| `shield_front` | object\|null | null | см. §3.7 |
| `drop_emoji` | string\|null | null | emoji для спавна при смерти |
| `score_value` | number\|null | null | очки за убийство |
| `explosion_on_death` | object\|null | null | см. §3.7 |
| `actions[]` | array | `[]` | см. §3.5 |

#### `pickup`

| Поле | Тип | Правило |
|---|---|---|
| `pickup_id` | string | `^[a-z0-9_]+$`, уникален в легенде |
| `effect` | enum | `score` \| `heal` \| `ammo` \| `key` |
| `value` | number | ≥ 0 |

#### `exit`

| Поле | Тип | Правило |
|---|---|---|
| `mode` | enum | `touch` \| `after_all` |
| `requires[]` | array | `pickup_id` (может быть пустым) |

#### `start`

Дополнительных полей нет.

#### `projectile`

| Поле | Тип | Default | Правило |
|---|---|---|---|
| `speed` | number | 4 | 0.5–20 |
| `damage` | number | 1 | 1–99 |
| `lifetime_ms` | number | 2000 | 100–10000 |
| `homing` | bool | `false` | — |
| `piercing` | bool | `false` | прошивает врагов |
| `bouncing` | bool | `false` | — |
| `bounces_max` | number | 0 | 0–10 |
| `returns` | bool | `false` | бумеранг |
| `pierces_walls` | bool | `false` | проходит сквозь solid |
| `friendly_fire` | bool | `false` | ранит своих |

#### `decor`

Дополнительных полей нет.

### 3.4. Композиты

Объект игры может занимать несколько клеток сетки. Допустимые размеры: **1×1, 1×2, 2×1, 2×2, 3×2, 2×3** (ширина × высота).

```json
"🏰": {
  "role": "obstacle",
  "name": "Замок",
  "solid": true,
  "composite": {
    "width": 3,
    "height": 2,
    "sprites": [
      ["🏰", "🏯", "🏰"],
      ["🏯", "🏰", "🏯"]
    ]
  }
}
```

**Правила:**

| Поле | Правило |
|---|---|
| `composite.width` | 1, 2, 3 |
| `composite.height` | 1, 2, 3 |
| Разрешённые пары | (1,1), (1,2), (2,1), (2,2), (3,2), (2,3) |
| `composite.sprites` | матрица `height × width` |
| `composite.sprites[0][0]` | **якорь** — совпадает с emoji элемента |
| Каждый спрайт | один графемный кластер |
| Якорь | уникален среди якорей и 1×1-элементов |
| Body-спрайт | может совпадать с другим body-спрайтом того же композита; не может совпадать с якорем другого композита |

**Свойства роли** (`solid`, `damage_on_touch`, `hp`, `actions`, `behavior_mode` и т. д.) применяются к **объекту целиком**, а не к отдельным клеткам.

**Якорь = emoji элемента.** Если `🏰` — якорь, то `composite.sprites[0][0]` должен быть `🏰`; остальные клетки — body-спрайты (`🏯`). При изменении emoji элемента — якорь обновляется.

### 3.5. Действия (`actions[]`)

Доступны для ролей `mob` и `collider`.

| Тип | Параметры | Смысл |
|---|---|---|
| `shoot` | `projectile` (emoji с ролью `projectile`), `rate_ms` (50–10000), `direction` (`toward_player` \| `fixed`); при `fixed` — `vector.dx`, `vector.dy` ∈ {-1,0,1} (не оба 0) | Стрельба снарядом |
| `melee` | `damage` (1–99), `cooldown_ms` (50–2000) | Ближний бой |
| `spawn` | `spawn_emoji` (emoji с ролью `mob`), `rate_ms` (100–10000), `max_alive` (1–20), `spawn_radius` (0–10, опц.) | Порождение мобов |
| `open` | `requires_id` (`pickup_id`) | Открытие двери/замка |
| `heal` | `amount` (1–99), `cooldown_ms` (50–2000) | Лечение |
| `damage_area` | `damage` (1–99), `radius` (1–10), `cooldown_ms` (50–2000) | Урон по площади |
| `teleport` | `to: {col, row}` | Телепортация |
| `cast` | `effect` (`freeze`\|`shield`\|`heal`\|`teleport`), `duration_ms`, `radius` | Магический эффект |
| `throw` | `projectile`, `power` (0.5–10.0) | Бросок предмета |
| `freeze` | `duration_ms` (100–10000), `radius` (1–10) | Заморозка |
| `push` | `radius` (1–5), `direction` (`away`\|`toward`) | Толчок |
| `use` | `effect` (строка) | Активация объекта |
| `on_stomp` | `damage` (1–99), `stun_ms` (0–5000), `morph_to` (emoji или null) | Прыжок на моба |
| `on_touch` | `effect` (строка) | Реакция при касании игроком |

**Валидация ссылок:**

- `shoot.projectile` — emoji с ролью `projectile` существует в эффективной легенде.
- `spawn.spawn_emoji` — emoji с ролью `mob` существует.
- `open.requires_id` — `pickup_id` существует.
- `on_stomp.morph_to` — emoji с ролью `mob` существует (если задан).

### 3.6. Формации (`vertical-scroll` только)

```json
"🛸": {
  "role": "mob",
  "behavior_mode": "trajectory",
  "movement_pattern": "sine_horizontal",
  "movement_params": {"speed": 2, "amplitude": 3, "frequency_hz": 0.5, "phase": 0},
  "formation": {
    "type": "v",
    "role": "leader",
    "spacing": 2
  }
}
```

| Поле | Правило |
|---|---|
| `formation.type` | `v` \| `line` \| `echelon` |
| `formation.role` | `leader` \| `follower` |
| `formation.spacing` | 1–5 |

**Поведение:**

- `leader` летит по своему паттерну.
- `follower` наследует паттерн лидера с постоянным смещением по `formation.type`.
- При уничтожении лидера ведомые переходят в `straight_down`.
- Один `leader` на формацию.

### 3.7. Боевые свойства моба

#### `weak_point`

```json
{
  "offset": {"x": 0, "y": -1},
  "radius_tiles": 1,
  "damage_multiplier": 2.0,
  "only_weak_point_vulnerable": false
}
```

| Поле | Правило |
|---|---|
| `offset.x`, `offset.y` | −3…3 (смещение относительно якоря) |
| `radius_tiles` | 1–3 |
| `damage_multiplier` | 1.0–10.0 |
| `only_weak_point_vulnerable` | bool |

Урон применяется только при попадании в уязвимую зону (если `only_weak_point_vulnerable: true`); иначе — множитель применяется только при попадании в зону.

#### `shield_front`

```json
{
  "direction_cone_deg": 90,
  "damage_multiplier": 0.0
}
```

| Поле | Правило |
|---|---|
| `direction_cone_deg` | 30–180 |
| `damage_multiplier` | 0.0–1.0 (0.0 — полная блокировка) |

Урон с фронтального сектора (относительно текущего направления моба) умножается на `damage_multiplier`.

#### `explosion_on_death`

```json
{
  "radius": 2,
  "damage": 2,
  "damages_player": true,
  "damages_mobs": false
}
```

| Поле | Правило |
|---|---|
| `radius` | 1–10 |
| `damage` | 1–99 |
| `damages_player` | bool |
| `damages_mobs` | bool |

Взрыв при смерти моба.

---

## 4. UI: вкладка «Основное»

### 4.1. Layout

```
┌─ Основное ─┬─ Легенда ─┬─ Уровни ─┬─ Управление ─┬─ Настройки ─┐
│  (активна) │           │          │              │             │
└────────────┴───────────┴──────────┴──────────────┴─────────────┘
┌─ Основное ──────────────────────────────────────────────────────┐
│                                                                  │
│  Название *                 [Золото Бунгелингов__________]      │
│                                                                  │
│  Slug *                     [zoloto-bungelingov_________]        │
│                             [Сгенерировать из названия] [✎]     │
│                                                                  │
│  Жанр *                     [Lode Runner-подобные ▼]            │
│                             (5 вариантов из genres.json)        │
│                                                                  │
│  Описание *                 [Одноэкранная головоломка:____]     │
│                             [соберите всё золото и уйдите_]     │
│                             [через выход._________________]     │
│                             (20–1000 символов, счётчик: 120)    │
│                                                                  │
│  Обложка *                                                       │
│  ┌───────────────────────────────┐                              │
│  │                               │                              │
│  │      [превью 640×480]         │  [Заменить] [Удалить]       │
│  │                               │                              │
│  └───────────────────────────────┘                              │
│  (или dropzone «Перетащите или нажмите»)                        │
│                                                                  │
│  Категории                                                       │
│  ☑ Аркады (arcade)                                              │
│    ☑ Шутеры (shooter)                                           │
│  ☑ Головоломки (puzzle)                                         │
│  ☐ Приключения (adventure)                                      │
│                                                                  │
│  Статус *                                                        │
│  (•) Черновик  ( ) Опубликована                                 │
│  ℹ️ Для публикации: обложка + минимум 1 уровень                │
│                                                                  │
│  ── SEO (используется в публичной части) ──                    │
│  SEO Title       [___________________________________]          │
│  SEO Description [___________________________________]          │
│                                                                  │
│  [Сохранить]   [Сохранить и закрыть]   [Удалить игру]           │
└──────────────────────────────────────────────────────────────────┘
```

### 4.2. Поля

| Поле | Тип | Валидация |
|---|---|---|
| Название | text | 1–120, обязательно |
| Slug | text | `^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$`, уникален; на создании автогенерируется из названия; после сохранения — read-only (кнопка «Изменить» с модалкой) |
| Жанр | select | 5 жанров, обязательно; после сохранения — смена через модалку |
| Описание | textarea | 20–1000, счётчик |
| Обложка | dropzone | файл `cover.webp` 640×480; см. §4.3 |
| Категории | multi-select дерева | 0..N; без дубликатов; включает потомков при выборе родителя |
| Статус | radio | `draft` \| `published` |
| SEO Title | text | 0–120 |
| SEO Description | textarea | 0–300 |

### 4.3. Загрузка обложки

**Требования:**

| Этап | Требование |
|---|---|
| Приём | `multipart/form-data`, поле `cover`; максимум 5 MB |
| MIME | `finfo_file()` ∈ `{image/jpeg, image/png, image/webp, image/gif}` |
| Размеры | `getimagesize()` 200×150 … 4000×3000 |
| Ре-энкод | Обязателен; оригинал не сохраняется |
| Результат | WebP q=80, **640×480** с letterbox (фон `#000022`) |
| Перезапись | `cover.webp` перезаписывается; старая версия → `cover-prev.webp` |
| Транзакция | При ошибке записи — JSON не обновляется |

**Почему 640×480 именно для обложки:** обложка — «витрина», она не связана с игровым экраном 32×24 и рендерится в публичной части на разных разрешениях. Приведение к стандартному размеру обеспечивает единообразие карточек каталога.

**Обоснование letterbox:** обложки — чаще всего горизонтальные (4:3); при загрузке вертикальной картинки letterbox сохраняет пропорции, не обрезая контент.

### 4.4. Наследование от жанра

При создании игры — блоки отображаются как read-only:

```
┌─ Унаследовано из жанра (read-only) ────────────────────────────┐
│  ▼ Управление (8 действий)                                     │
│  ▼ Звуки (5 событий)                                           │
│  ℹ️ Редактирование на вкладках «Управление» и «Настройки».    │
└────────────────────────────────────────────────────────────────┘
```

**Смена жанра после сохранения** — модалка:

```
⚠ Смена жанра
Вы меняете жанр с «Lode Runner-подобные» на «Платформеры».

Будут перезаписаны:
  • controls
  • sounds
  • engine_overrides (очищен)

Уровни игры могут стать несовместимыми с новым движком.

Для подтверждения введите slug игры: [____________]

[Отмена]                                [Подтвердить]
```

После подтверждения: `controls`, `sounds` перезаписываются значениями нового жанра; `engine_overrides` очищается; `audit.log` → `game.genre_change`.

### 4.5. Slug

- **На создании** — генерируется из названия (транслитерация кириллицы → латиница, lowercase, `[^a-z0-9-]` → `-`, коллапс `-`, trim по краям).
- **После сохранения** — read-only; кнопка ✎ открывает модалку «Изменение slug сломает существующие ссылки. Для подтверждения введите новый slug: [____]».
- **При дубликате** — суффикс `-2`, `-3`, … (автоматически) или ошибка (при ручном вводе).

### 4.6. Удаление игры

Кнопка «Удалить игру» внизу.

Модалка:
- Ввод `title` для подтверждения.
- Предупреждение: «Будут удалены: JSON карточки, обложка и её превью, каталог `media/{game-id}/`, все уровни и триггеры. Действие необратимо.»

После удаления — `audit.log` → `game.delete`; редирект на `/admin/games`; flash «Игра „X" удалена».

### 4.7. Ссылочная целостность

- Удаление жанра (блок 4) блокируется, если есть игра с этим `genre_id`.
- Удаление категории блокируется, если игра её использует.
- Удаление игры — всегда разрешено (после подтверждения).

---

## 5. UI: вкладка «Легенда»

### 5.1. Layout

```
┌─ Основное ─┬─ Легенда ─┬─ Уровни ─┬─ Управление ─┬─ Настройки ─┐
│            │ (активна) │          │              │             │
└────────────┴───────────┴──────────┴──────────────┴─────────────┘
┌─────────────────────────────────────────────────────────────────┐
│  [+ Добавить emoji]  [Импортировать из жанра]  [Экспорт]       │
├──────────────────┬──────────────────────────────────────────────┤
│  СПИСОК          │  РЕДАКТОР ВЫБРАННОГО ЭЛЕМЕНТА                │
│                  │                                              │
│  🔍 [поиск...]   │  Emoji: [🌳] [Открыть пикер]                │
│  Роль:           │  Название: [Дерево_______________]           │
│  [Все][Фон][...] │  Описание: [____________________]            │
│                  │  Роль: [obstacle ▼]                          │
│  🟩 Трава   фон  │                                              │
│  🌳 Дерево прегр.│  ── Свойства преграды ──                    │
│  🏹 Робин старт  │  ☑ Твёрдая (solid)                           │
│  🦹 Солдат моб   │  ☐ Копаемая (diggable)                       │
│  ➡️ Стрела снаряд│  ☐ Лазаемая (climbable)                      │
│  🔑 Ключ   предм.│  ☐ Блокирует снаряды                         │
│  🚪 Дверь  выход │  ☐ Разрушаемая                               │
│  ...             │                                              │
│                  │  ── Составной объект ──                      │
│  ── Из жанра ──  │  Размер: [1×1 ▼]                             │
│  🧱 Кирпич       │                                              │
│  💰 Золото       │  [Сохранить]  [Удалить]                      │
└──────────────────┴──────────────────────────────────────────────┘
```

### 5.2. Список элементов

- Таблица: emoji (крупно), название, роль (бейдж с цветом), размер композита (если есть), количество действий (для mob/collider), кнопки ✎/🗑.
- **Фильтр по роли** — кнопки-вкладки: «Все», «Фон», «Преграда», «Коллайдер», «Моб», «Предмет», «Выход», «Старт», «Снаряд», «Декор».
- **Поиск** — по emoji и `name`.
- Клик по строке → загрузка элемента в редактор справа.

**Секция «Из жанра (наследуется):»** — элементы `genre.default_legend`, не переопределённые в `game.legend`. Read-only; кнопка «Клонировать в игру» у каждого создаёт копию в `game.legend` с правом редактирования.

### 5.3. Emoji-picker

Модалка с двумя вкладками:

**«Стандартные»** — Unicode-эмодзи по категориям:

| Категория | Примеры |
|---|---|
| Игровые | 🔫🎯💣🗡️⚔️🏹🛡️💎💊🔑🗝️🚪⭐❤️ |
| Персонажи | 🏃🧙🧝🧟👾👻🤖🐸🦹 |
| Природа | 🌳🌲🌴🪨🌊🔥❄️ |
| Еда | 🍎🍖🍌🍄🧪 |
| Здания | 🏰🏠🚪⛲🗿 |
| Символы | 🟩🟥🟦⬛⬜🟨🔴🟢🔵 |
| Прочее | ... |

**«Банк»** — кастомные эмодзи из `/api/emoji-bank.json`, сгруппированные по категориям (персонажи, оружие, природа, UI, символы, другое). Каждый — `<img>` 32×32. Анимации — с бейджем «▶».

**Ручной ввод** — поле «Или введите emoji». Валидация: один графемный кластер. PUA-кодпоинт проверяется против банка.

### 5.4. Редактор элемента

Форма справа. Поля зависят от роли. **Общие поля:**

1. **Emoji** — текущий emoji + кнопка «Открыть пикер». При изменении на существующий — ошибка.
2. **Название** — 2–80, обязательно.
3. **Описание** — 0–300, опционально.
4. **Роль** — выпадающий список из 9.

**Специфичные поля — динамически:**

- **`background`**: `walkable: true` (disabled).
- **`obstacle`**: `solid: true` (disabled), `diggable`, `climbable`, `hangable`, `destructible`, `blocks_projectiles`.
- **`collider`**: `solid: true` (disabled), `blocks_projectiles`, `damage_on_touch`, блок «Действия».
- **`mob`**: `hp`, `damage_on_touch`, `behavior_mode` (ai/trajectory/hybrid), блок «Поведение» (см. §6), блок «Боевые свойства» (см. §7), блок «Действия».
- **`pickup`**: `pickup_id`, `effect`, `value`.
- **`exit`**: `mode`, `requires[]` (мультиселект `pickup_id`).
- **`start`**: без доп. полей.
- **`projectile`**: `speed`, `damage`, `lifetime_ms`, `homing`, `piercing`, `bouncing`, `bounces_max`, `returns`, `pierces_walls`, `friendly_fire`.
- **`decor`**: без доп. полей.

### 5.5. Блок «Композит»

Под полем «Роль» — свёрнутый блок **«Составной объект»**:

```
┌─ Составной объект ──────────────────────────────────────┐
│                                                          │
│  Размер:  [1×1 ▼]  (1×1, 1×2, 2×1, 2×2, 3×2, 2×3)      │
│                                                          │
│  (при размере > 1×1)                                     │
│  ┌─ Спрайты ─────────────────────────────────────────┐  │
│  │  ┌─────┬─────┬─────┐                              │  │
│  │  │ 🏰  │ 🏯  │ 🏰  │  ← якорь (read-only, = emoji)│  │
│  │  ├─────┼─────┼─────┤                              │  │
│  │  │ 🏯  │ 🏰  │ 🏯  │  ← body-спрайты             │  │
│  │  └─────┴─────┴─────┘                              │  │
│  │                                                    │  │
│  │  Клик по body-ячейке → emoji-picker                │  │
│  └────────────────────────────────────────────────────┘  │
│                                                          │
│  ── Превью ──                                            │
│  ┌────────────┐                                          │
│  │  [canvas]  │                                          │
│  └────────────┘                                          │
└──────────────────────────────────────────────────────────┘
```

**Поведение:**

- Ячейка `[0,0]` = якорь = emoji элемента, read-only.
- Body-спрайты редактируются через emoji-picker (клик по ячейке).
- Кнопка «Копировать из якоря» рядом с каждой body-ячейкой — быстро заполнить.
- Превью — canvas с отрисовкой композита.
- Анимации body-спрайтов отображаются как первый кадр с «▶»; в превью проигрываются.

**Смена размера** — сбрасывает матрицу (модалка предупреждения).

### 5.6. Блок «Действия»

Доступен для `mob` и `collider`.

```
┌─ Действия ──────────────────────────────────────────────┐
│  [+ Добавить действие]                                  │
│                                                          │
│  ┌─ Действие 1 ─────────────────────────────────┐ [✕] │
│  │  Тип: [shoot ▼]                              │    │
│  │  Снаряд: [➡️ ▼]  (только projectile)         │    │
│  │  Интервал: [1500] мс (50–10000)              │    │
│  │  Направление: [toward_player ▼]              │    │
│  │                                              │    │
│  └──────────────────────────────────────────────┘    │
│                                                          │
│  ┌─ Действие 2 ─────────────────────────────────┐ [✕] │
│  │  Тип: [melee ▼]                              │    │
│  │  Урон: [1]  Кулдаун: [500] мс               │    │
│  └──────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────────┘
```

**Поля по типу** — см. §3.5.

**Валидация `projectile`:** если в легенде нет emoji с ролью `projectile`, селект пуст и показывает «Сначала добавьте emoji с ролью „снаряд"». Кнопка «Сохранить действие» блокируется.

**Валидация `spawn_emoji`:** аналогично для роли `mob`.

### 5.7. Импорт из жанра

Модалка:

```
┌─ Импорт из жанра ───────────────────────────────────────┐
│  Жанр: Lode Runner-подобные                             │
│                                                          │
│  ☑ 🧱 Кирпич       (obstacle)                          │
│  ☑ 💰 Золото       (pickup)                            │
│  ☑ 🚪 Выход        (exit)                              │
│  ☐ 🏃 Игрок        (start)  — уже в игре               │
│                                                          │
│  [Импортировать выбранные]                  [Отмена]    │
└──────────────────────────────────────────────────────────┘
```

- Уже существующие emoji в `game.legend` — disabled с пометкой «уже в игре».
- Импорт добавляет выбранные в `game.legend`.
- `audit.log` → `legend.import_from_genre`.

### 5.8. Клонирование

В секции «Из жанра (наследуется)» — кнопка «Клонировать в игру»:
- Создаёт копию элемента `default_legend` в `game.legend` под тем же emoji.
- Модалка предупреждения: «Клонирование делает элемент редактируемым. Изменения дефолтной легенды жанра не будут влиять на эту игру.»
- После клонирования — элемент уходит из секции «Из жанра» и появляется в основном списке.

### 5.9. Удаление emoji

При удалении emoji, на который ссылаются:

- `exit.requires[]` — предупреждение «Emoji X используется в requires выхода Y»; при подтверждении — ссылка удаляется из `requires[]`.
- `actions[].shoot.projectile` — предупреждение «Emoji X используется как projectile действия у Y»; действие удаляется из Y.
- `actions[].spawn.spawn_emoji` — аналогично.
- `actions[].on_stomp.morph_to` — аналогично.
- `player_attacks.ranged.projectile_emoji` — предупреждение «Emoji X используется как снаряд игрока»; `ranged.enabled` устанавливается в `false`.

Все изменения записываются в `audit.log` → `legend.element_delete` с `broken_refs`.

### 5.10. Валидация легенды

| Правило | Проверка |
|---|---|
| Ровно один `start` в эффективной легенде | Проверка |
| `pickup_id` уникален | Проверка |
| `exit.requires[]` ⊆ `pickup_id` | Проверка |
| `actions[].shoot.projectile` — существует `projectile` | Проверка |
| `actions[].spawn.spawn_emoji` — существует `mob` | Проверка |
| `actions[].on_stomp.morph_to` — существует `mob` (если задан) | Проверка |
| Композиты: `(width, height)` ∈ разрешённых | Enum |
| Композиты: матрица `height × width` | Проверка |
| Композиты: якорь = emoji элемента | Проверка |
| Композиты: якорь уникален | Проверка |
| Композиты: body не совпадает с якорем другого композита | Проверка |
| `behavior_mode` ∈ {ai, trajectory, hybrid} | Enum |
| `ai_model` ∈ библиотеки, поддерживает движок (при `behavior_mode ∈ {ai, hybrid}`) | Проверка |
| `movement_pattern` ∈ библиотеки, поддерживает движок (при `behavior_mode ∈ {trajectory, hybrid}`) | Проверка |
| `ai_params` соответствует схеме | Проверка |
| `movement_params` соответствует схеме | Проверка |
| `formation` только для `vertical-scroll` | Проверка |
| `weak_point.offset` ∈ [−3, 3] | Проверка |
| `shield_front.direction_cone_deg` ∈ [30, 180] | Проверка |

---

## 6. Блок «Поведение» моба

При выборе роли `mob` — свёрнутый блок **«Поведение»**:

```
┌─ Поведение ──────────────────────────────────────────────┐
│                                                          │
│  Режим:  (•) AI   ( ) Траектория   ( ) Гибрид           │
│                                                          │
│  ── AI-модель ──                                        │
│  [patrol_horizontal ▼]  (фильтр по движку)              │
│  Патруль по горизонтали                                 │
│                                                          │
│  ── Параметры AI ──                                     │
│  speed         [0.4   ] (0.1–3.0)                      │
│  range         [5     ] (1–30)                         │
│  turn_on_wall  [✓]                                      │
│  turn_on_edge  [✓]                                      │
│  pause_at_ends_ms [0  ] (0–5000)                       │
│                                                          │
│  [Сбросить к дефолтам модели]                          │
│                                                          │
│  ── Формация (только vertical-scroll) ──               │
│  [Не участвует ▼]  (V / Линия / Эшелон)                │
│                                                          │
│  [▶ Тест поведения]                                     │
└──────────────────────────────────────────────────────────┘
```

**Логика:**

- **`ai`** — только блок «AI-модель» + «Параметры AI».
- **`trajectory`** — только блок «Траектория» + «Параметры траектории» (аналог AI).
- **`hybrid`** — оба блока.
- Поля неактивного режима **сохраняются в JSON**, но не отображаются.

**Селектор `ai_model`:**

- Только модели с `supported_engines`, содержащим движок жанра игры.
- Группировка по `category`.
- Поиск по `name`/`id`.
- При выборе — форма `ai_params` перестраивается по `params[]` модели.
- «Сбросить к дефолтам модели» — заполняет `default` из модели.
- Ссылка «Открыть библиотеку» → `/admin/ai-models`.

**Селектор `movement_pattern`** — аналогично, только по `movement-patterns.json`. С анимированным превью траектории (мини-canvas 40×40).

**Формации** — только для `vertical-scroll` и `movement_pattern`. Один `leader` на формацию.

**«Тест поведения»** — модалка с предпросмотром уровня; см. блок 10, §6.

---

## 7. Блок «Боевые свойства» моба

При выборе роли `mob` — свёрнутый блок **«Боевые свойства»**:

```
┌─ Боевые свойства ────────────────────────────────────────┐
│                                                          │
│  HP                    [3   ]  (1–99)                   │
│  Урон при касании      [1   ]  (0–10)                   │
│  Броня                 [0   ]  (0–10)                   │
│  Сопротивление отбрасыванию [0.0 ] (0.0–1.0)           │
│  Сопротивление оглушению    [0.0 ] (0.0–1.0)           │
│  Очки за убийство      [—   ]  (пусто = дефолт движка) │
│  Дроп при смерти       [— ▼ ]  (emoji или «—»)         │
│                                                          │
│  ── Взрыв при смерти ──                                 │
│  ☐ Есть                                                 │
│   Радиус [2]  Урон [2]                                  │
│   ☐ Ранит игрока  ☐ Ранит мобов                         │
│                                                          │
│  ── Уязвимая точка ──                                   │
│  ☐ Есть                                                 │
│   Смещение X [-1..1] Y [-1..1]                          │
│   Радиус [1]  (1–3)                                     │
│   Множитель урона [2.0]  (1.0–10.0)                     │
│   ☐ Только в уязвимую точку                             │
│                                                          │
│  ── Фронтальный щит ──                                  │
│  ☐ Есть                                                 │
│   Угол конуса [90]° (30–180)                            │
│   Множитель урона [0.0]  (0.0–1.0)                      │
└──────────────────────────────────────────────────────────┘
```

Поля соответствуют схеме §3.3 (`mob`) и §3.7.

**Интеграция с боевой системой (блок 10):** эти поля используются движком при применении урона.

---

## 8. Валидация

### 8.1. Вкладка «Основное»

| Поле | Правило |
|---|---|
| `title` | 1–120 |
| `slug` | `^[a-z0-9][a-z0-9-]{1,78}[a-z0-9]$`, уникален |
| `genre_id` | существует |
| `description` | 20–1000 |
| `cover` | файл существует, 640×480 WebP |
| `category_ids` | каждый `id` существует |
| `status` | `draft` \| `published` |
| `meta.seo_title` | 0–120 |
| `meta.seo_description` | 0–300 |

**Публикация:** `status: published` требует наличия `cover` и хотя бы одного уровня.

### 8.2. Вкладка «Легенда»

См. §5.10.

### 8.3. Общая валидация сохранения

Атомарное сохранение: если хотя бы на одной вкладке есть ошибки — сохранение блокируется, вкладка подсвечивается, первая ошибка фокусируется.

---

## 9. Безопасность

| Угроза | Мера |
|---|---|
| XSS через `title`/`description`/`name` | `htmlspecialchars()` при выводе |
| XSS через `legend` emoji (HTML-тег) | Проверка, что emoji — один графемный кластер; HTML-теги отклоняются |
| Path traversal через `{game-id}` в URL | Regex `^game-\d{3,}$` |
| Path traversal в `cover.path` | Сервер генерирует путь сам; `cover.path` из формы игнорируется |
| CSRF | Токен из блока 3 |
| Подмена `controls`/`sounds`/`engine_overrides` при сохранении «Основного» | Сервер игнорирует эти поля из формы на вкладке «Основное»; при создании — из жанра; при редактировании — берутся из текущего JSON |
| Подмена `legend` при сохранении «Основного» | Сервер игнорирует; редактируется только на вкладке «Легенда» |
| Загрузка огромного файла обложки | `upload_max_filesize` + `filesize` в коде |
| Decompression bomb в обложке | `getimagesize` до создания ресурса; лимит 4000×3000 |
| Исполнение PHP в `/media/{game-id}/` | `php_flag engine off` (блок 3) |
| Подмена `custom`/`role` при POST | Сервер игнорирует неизвестные поля; `role` — enum |

---

## 10. Аудит

```json
{"ts":"...","user":"admin","action":"game.create","target":"game-001","details":{"title":"Золото Бунгелингов","genre_id":"genre-loderunner"}}
{"ts":"...","user":"admin","action":"game.update","target":"game-001","details":{"changed":["title","description","category_ids"]}}
{"ts":"...","user":"admin","action":"game.cover_update","target":"game-001","details":{"bytes":45123,"mime":"image/jpeg"}}
{"ts":"...","user":"admin","action":"game.status_change","target":"game-001","details":{"old":"draft","new":"published"}}
{"ts":"...","user":"admin","action":"game.genre_change","target":"game-001","details":{"old":"genre-loderunner","new":"genre-platformer","controls_reset":true,"sounds_reset":true,"overrides_cleared":true}}
{"ts":"...","user":"admin","action":"game.delete","target":"game-001","details":{"title":"Золото Бунгелингов"}}
{"ts":"...","user":"admin","action":"legend.element_create","target":"game-001","details":{"emoji":"🦹","role":"mob"}}
{"ts":"...","user":"admin","action":"legend.element_update","target":"game-001","details":{"emoji":"🦹","changed":["hp","actions","ai_model"]}}
{"ts":"...","user":"admin","action":"legend.element_delete","target":"game-001","details":{"emoji":"🦹","broken_refs":{"actions":[],"requires":[]}}}
{"ts":"...","user":"admin","action":"legend.import_from_genre","target":"game-001","details":{"genre_id":"genre-loderunner","imported":["🧱","💰","🚪"]}}
{"ts":"...","user":"admin","action":"legend.clone_from_genre","target":"game-001","details":{"emoji":"🧱"}}
{"ts":"...","user":"admin","action":"legend.mob_behavior_mode_change","target":"game-001","details":{"emoji":"🦹","old":"ai","new":"hybrid"}}
{"ts":"...","user":"admin","action":"legend.mob_ai_set","target":"game-001","details":{"emoji":"🦹","ai_model":"chase_with_vision","params":{"speed":0.45,"vision_range":8}}}
{"ts":"...","user":"admin","action":"legend.mob_pattern_set","target":"game-001","details":{"emoji":"🦹","movement_pattern":"sine_horizontal"}}
```

---

## 11. Критерии приёмки блока 7

### 11.1. Карточка игры

1. **Создание.** `/admin/games/new` — форма с пятью вкладками; активна «Основное»; поля обязательные.
2. **Slug автогенерируется.** При вводе названия «Золото Бунгелингов» — slug `zoloto-bungelingov`. Дубликат → `-2`.
3. **Обложка.** Загрузка JPG/PNG/WebP/GIF → `cover.webp` 640×480 WebP q=80; предыдущая → `cover-prev.webp`. PDF — ошибка. >5 MB — ошибка. 5000×4000 — ошибка.
4. **Категории.** Мультиселект дерева; выбор родителя включает потомков; JSON — плоский массив без дубликатов.
5. **Статус.** `draft` по умолчанию. Попытка публикации без обложки или уровней — ошибка.
6. **Смена жанра.** После сохранения — модалка с вводом slug; при подтверждении `controls`/`sounds` перезаписываются, `engine_overrides` очищается, `audit.log` → `game.genre_change`.
7. **Удаление.** Модалка с вводом `title`; удаляются JSON, `media/{game-id}/`, запись в `audit.log`.
8. **Пять вкладок.** Все активны; переключение без перезагрузки; кнопка «Сохранить» атомарна.
9. **Ссылочная целостность.** Удаление используемого жанра/категории (из блока 4) блокируется.

### 11.2. Легенда

10. **Вкладка «Легенда» активна.** Пустая — «Легенда пуста. Добавьте первый emoji или импортируйте из жанра».
11. **Добавление emoji.** `+ Добавить emoji` → форма; выбор `🟩` с ролью `background` → сохранение.
12. **Emoji-picker.** Две вкладки: «Стандартные» (Unicode по категориям) и «Банк» (кастомные из `emoji-bank.json`). Ручной ввод «🌳» — принимается; «abc» — ошибка; «🌳🌳» — ошибка.
13. **Все 9 ролей.** Создаются элементы всех ролей с соответствующими полями.
14. **Действия.** `collider` «Турель» с `shoot` — селект `projectile` пуст без projectile в легенде. После создания `➡️` — выбор доступен.
15. **Валидация ссылок.** `shoot` с несуществующим `projectile` — ошибка. `spawn.spawn_emoji` без `mob` — ошибка.
16. **Дубликаты emoji.** Второй `🟩` — ошибка.
17. **Один `start`.** Второй `start` — ошибка.
18. **`pickup_id` уникален.** Второй `key_red` — ошибка.

### 11.3. Композиты

19. **Блок «Составной объект».** Размер `1×1` — блок спрайтов скрыт. Размер `2×2` — матрица 2×2; якорь read-only.
20. **Недопустимые размеры.** `3×1`, `1×3`, `3×3`, `4×2` — отсутствуют в селекте.
21. **Валидация якоря.** Попытка использовать `🏰` как якорь для двух элементов — ошибка.
22. **Превью.** Canvas показывает композит с правильными пропорциями.
23. **Отображение в списке.** Бейдж `[3×2]` рядом с ролью.
24. **Body-спрайт вручную.** Попытка добавить `1×1`-элемент с body-спрайтом вне композита — валидация при сохранении уровня ловит (блок 8).

### 11.4. Поведение моба

25. **Блок «Поведение» активен** для всех мобов.
26. **Переключение режимов.** `ai` → только AI-блок. `trajectory` → только паттерн-блок. `hybrid` → оба. Поля неактивного режима сохраняются в JSON.
27. **Фильтр `ai_model`.** Только модели, поддерживаемые движком жанра.
28. **Форма `ai_params`.** `number` → числовое поле с диапазоном; `boolean` → чекбокс; `enum` → селект.
29. **Сброс к дефолтам.** Кнопка заполняет `default` из модели.
30. **Селектор `movement_pattern`.** С анимированным превью траектории.
31. **Формации** (`vertical-scroll`). Один `leader`, `follower` наследуют паттерн.

### 11.5. Боевые свойства моба

32. **Блок «Боевые свойства»** доступен для всех мобов.
33. **HP, armor, resistances** — валидируются.
34. **`weak_point`.** При `only_weak_point_vulnerable: true` — урон вне зоны игнорируется (проверяется в игре).
35. **`shield_front`.** Урон с фронта блокируется (0.0).
36. **`drop_emoji`.** При смерти моба — emoji спавнится.
37. **`explosion_on_death`.** Взрыв с радиусом и уроном.

### 11.6. Импорт/клонирование

38. **Импорт из жанра.** Модалка со списком `default_legend`; импорт выбранных в `game.legend`; уже существующие — disabled.
39. **Секция «Из жанра».** Элементы `default_legend`, не переопределённые в игре, видны отдельно; кнопка «Клонировать в игру».

### 11.7. Наследование

40. **Итоговая легенда.** Создана игра с `🧱` (переопределение), жанр имеет `🧱`, `💰`, `🚪`. Итоговая легенда = 3 элемента (по ключу emoji, совпадения перезаписываются).

### 11.8. Безопасность и аудит

41. **XSS в названии.** `<script>` — экранировано.
42. **XSS в emoji.** HTML-тег в поле emoji — отклонён.
43. **CSRF.** POST без токена — 403.
44. **Аудит.** Все операции с карточкой и легендой записаны в `audit.log`.
45. **Обратная совместимость.** Критерии блоков 1–6, 10 выполняются.

---

## 12. Оценка

**Ориентир:** 4–5 человеко-дней на разработчика (или ~1,5 рабочих дня с ИИ-ассистентом).

Основное время:

- **Карточка с пятью вкладками** — layout, переключение, атомарное сохранение (≈1 день).
- **Форма обложки** — загрузка, ресайз, cover-prev, транзакционность (≈0,5 дня).
- **Легенда** — список с фильтрами, форма с 9 ролями, динамические поля (≈1,5 дня).
- **Композиты** — матрица спрайтов, якорь, превью (≈0,5 дня).
- **Действия** — динамический список с 14 типами (≈0,5 дня).
- **Блок «Поведение»** — переключение режимов, фильтрация моделей, формы параметров (≈0,5 дня).
- **Блок «Боевые свойства»** — 6 полей + weak_point + shield_front + explosion (≈0,3 дня).
- **Валидация** — 30+ правил (≈0,5 дня).
- **Отладка и тестирование** (≈0,5 дня).

---

## 13. Что не делаем в блоке 7 и почему

- **Не реализуем редактор уровней** — блок 8. Вкладка «Уровни» — заглушка до блока 8.
- **Не реализуем управление и звуки** — блок 9. Вкладки read-only до блока 9.
- **Не реализуем тест поведения** — блок 10, §6. Модалка «Тест поведения» вызывается из легенды, но её реализация — блок 10.
- **Не реализуем публичную карточку** — блок 12. На этом шаге карточка — только в админке.
- **Не реализуем AI-модели и паттерны** — блок 5. На этом шаге они только выбираются.
- **Не реализуем генерацию превью уровня** — блок 8. В JSON `thumb` — пустая строка до блока 8.
- **Не реализуем SEO на публичной части** — блок 12. `meta.seo_*` заполняются, но не используются до блока 12.
- **Не реализуем массовые операции с играми** — блок 12.
- **Не реализуем копирование настроек между играми** — блок 12.
- **Не реализуем геймпад/тач** — только клавиатура.

---

**Конец блока 7 из 13.**

Следующий блок — **Уровни и редактор уровней** (emoji-сетка 32×24 / 32×N / M×24, визуальный редактор, композиты в сетке, триггеры, условия победы/поражения, автогенерация превью).


# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 8. Уровни и редактор уровней

## 1. Общее описание

Уровни — **emoji-сетка**, в которой каждый символ соответствует элементу легенды (роль, свойства, действия). Уровни хранятся внутри карточки игры в массиве `levels[]`.

**Единый экран ZX Spectrum 32×24** — основа всех уровней:

| Движок жанра | Размер сетки |
|---|---|
| `static-screen` | **ровно 24 строки × 32 emoji** |
| `vertical-scroll` | **N строк × 32 emoji**, N ∈ [24, 500] |
| `horizontal-scroll` | **24 строки × M emoji**, M ∈ [32, 800] |

Мир скролл-игр больше экрана, но **окно рендера — всегда 32×24**. Редактор уровня отображает мир целиком (с прокруткой и мини-картой) либо окно 32×24 (для `static-screen` — всё поле).

**Основной режим редактора — визуальный grid** (Canvas). Textarea — вспомогательный, для bulk edit. Оба режима синхронизированы.

**Композиты** (объекты, занимающие несколько клеток, из блока 7) размещаются целиком: клик по якорю заполняет все `N` клеток спрайтами.

**Триггеры, условия победы/поражения** — редактируются на вкладке «Триггеры» / в параметрах уровня; детальная схема — блок 10.

**Превью уровня** (`thumb.webp`) генерируется на клиенте при сохранении и используется в галерее уровней на публичной карточке игры (блок 12).

---

## 2. Схема уровня

### 2.1. `levels[]` в карточке игры

```json
{
  "level_id": "level-001",
  "name": "Вход в шахту",
  "order": 1,
  "grid": [
    "🧱 🧱 🧱 ... 🧱",
    "🧱 🟩 🏹 ... 🧱",
    "..."
  ],
  "thumb": "media/game-001/level-001-thumb.webp",
  "params": {
    "time_limit_seconds": 120,
    "triggers": [],
    "victory_conditions": [{"type": "reach_exit"}],
    "defeat_conditions": [{"type": "hp_zero"}, {"type": "lives_zero"}],
    "checkpoints": []
  },
  "created_at": "2026-10-21T15:00:00Z",
  "updated_at": "2026-10-21T15:30:00Z"
}
```

### 2.2. Поля

| Поле | Обязательно | Правило |
|---|---|---|
| `level_id` | ✅ | `^level-\d{3,}$`, уникален в игре |
| `name` | ✅ | 1–80 |
| `order` | ✅ | целое; уникален в игре; пересчитывается при сохранении/удалении |
| `grid` | ✅ | массив строк; формат — §2.3 |
| `thumb` | ❌ | путь к превью 320×240 WebP; пустая строка до первого сохранения |
| `params.time_limit_seconds` | ❌ | целое ≥ 0; `0` — без лимита |
| `params.triggers` | ❌ | ≤ 50 триггеров (блок 10, §4) |
| `params.victory_conditions` | ❌ | блок 10, §5 |
| `params.defeat_conditions` | ❌ | блок 10, §5 |
| `params.checkpoints` | ❌ | для `horizontal-scroll` — `[{col, row}, ...]` |
| `created_at`, `updated_at` | ✅ | ISO 8601 |

### 2.3. Формат `grid`

- Массив строк, каждая строка — emoji через **один пробел**.
- Каждый emoji — **один графемный кластер**: Unicode, PUA-кодпоинт из банка или PUA-кодпоинт анимации.
- Все строки **одинаковой длины**.
- Каждый emoji **должен быть в эффективной легенде** (`{...genre.default_legend, ...game.legend}`).
- **Пустая клетка** — emoji с ролью `background` (например, `🟩` для платформера, `⬛` для космоса). Не пробел и не пустая строка.

**Размеры по движку:**

| Движок | Строк | Столбцов |
|---|---|---|
| `static-screen` | ровно **24** | ровно **32** |
| `vertical-scroll` | **N ∈ [24, 500]** | ровно **32** |
| `horizontal-scroll` | ровно **24** | **M ∈ [32, 800]** |

**Пример `static-screen` (24 строки × 32 emoji):**

```json
"grid": [
  "🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱",
  "🧱 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🧱",
  "...",
  "🧱 🏃 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🟩 🚪 🟩 🧱",
  "🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱"
]
```

### 2.4. Композиты в сетке

Если emoji — якорь композита (`composite.sprites[0][0]`), то:

- в сетке ячейка содержит якорь;
- все `N` клеток прямоугольника `height × width` от якоря содержат соответствующие спрайты из `composite.sprites`;
- **body-спрайты не могут появляться вне композита** (если это не самостоятельные 1×1-элементы легенды).

**Парсинг движком:**

1. Сканируются все якоря композитов; их прямоугольники помечаются «занятыми».
2. Проверка перекрытий и соответствия body-спрайтов.
3. Остальные клетки — 1×1-объекты.

### 2.5. `params.checkpoints` (только `horizontal-scroll`)

```json
"checkpoints": [
  {"col": 50, "row": 10},
  {"col": 120, "row": 10}
]
```

| Поле | Правило |
|---|---|
| `col` | 0 ≤ col < M |
| `row` | 0 ≤ row < 24 |
| Всего чекпоинтов | 0–20 |

Чекпоинт — невидимая точка; при смерти игрока — респавн на последнем пройденном чекпоинте (или на `start`, если пройдено 0).

### 2.6. Триггеры, условия победы/поражения

- `params.triggers[]` — до 50 триггеров. Схема, валидация, действия — блок 10, §4.
- `params.victory_conditions[]` — блок 10, §5.
- `params.defeat_conditions[]` — блок 10, §5.

В этом блоке — только UI их редактирования; исполнение — в движках (блок 11).

---

## 3. UI: вкладка «Уровни»

### 3.1. Список уровней

```
┌─ Основное ─┬─ Легенда ─┬─ Уровни ─┬─ Управление ─┬─ Настройки ─┐
│            │           │(активна) │              │             │
└────────────┴───────────┴──────────┴──────────────┴─────────────┘
┌─ Уровни ────────────────────────────────────────────────────────┐
│  [+ Создать уровень]              [Валидировать все уровни]    │
├─────────────────────────────────────────────────────────────────┤
│  ≡ │ # │ Превью  │ Название           │ Размер │ Параметры │   │
├────┼───┼─────────┼────────────────────┼────────┼───────────┼───┤
│  ≡ │ 1 │ [thumb] │ Вход в шахту       │ 32×24  │ ⏱120с    │✎📋🗑│
│  ≡ │ 2 │ [thumb] │ Лестничный колодец │ 32×24  │ ⏱180с    │✎📋🗑│
│  ≡ │ 3 │ [thumb] │ Лагерь шерифа      │ 32×24  │ —        │✎📋🗑│
└────┴───┴─────────┴────────────────────┴────────┴───────────┴───┘
```

**Колонки:**

- **≡** — drag-handle для переупорядочивания (HTML5 drag&drop).
- **#** — `order`.
- **Превью** — миниатюра `thumb.webp` 60×45; если нет — заглушка 🗺️.
- **Название** — ссылка на редактирование.
- **Размер** — `M×N` (для `static-screen` — `32×24`).
- **Параметры** — `time_limit_seconds`; значок ⚠ при наличии триггеров/условий (tooltip «N триггеров»).
- **Действия** — ✎ (редактировать), 📋 (клонировать), 🗑 (удалить).

**Кнопки:**

- **«+ Создать уровень»** — открывает модалку создания.
- **«Валидировать все уровни»** — прогон всех уровней по правилам; отчёт.

**Пустое состояние:** «Пока нет уровней. Создайте первый уровень →».

### 3.2. Создание уровня

Модалка:

```
┌─ Новый уровень ─────────────────────────────────────────┐
│                                                          │
│  Название:  [Уровень 1___________________]              │
│  Размер:    [32×24]  (зависит от движка жанра)         │
│                                                          │
│  ── Начальное заполнение ──                             │
│  (•) Пустая сетка (всё — emoji-пустота)                 │
│  ( ) Скопировать из уровня: [— ▼]                        │
│                                                          │
│  [Отмена]                              [Создать]        │
└──────────────────────────────────────────────────────────┘
```

**Логика:**

- Размер фиксирован движком (`static-screen` — 32×24; `vertical-scroll` — 32×24 стартово с возможностью расширения; `horizontal-scroll` — 32×24 стартово).
- «Пустая сетка» заполняется emoji с ролью `background` из эффективной легенды. Если такого нет — ошибка «В легенде нет элемента с ролью background».
- «Скопировать из уровня» — копирует `grid` другого уровня игры (для `static-screen` — точно совпадает; для скролл-движков — размеры должны совпадать; иначе ошибка).
- После создания — переход в редактор уровня.

### 3.3. Клонирование

Кнопка 📋 открывает модалку:
- Предлагает имя «Копия: {name}».
- Создаёт новый `level_id = level-NNN+1`, `order = max+1`, копирует `grid`, `params`.
- `thumb` — пустая строка (генерируется при первом сохранении).
- Открывает редактор нового уровня.

### 3.4. Удаление

Модалка:
- Ввод `name` для подтверждения.
- Предупреждение: «Уровень „X" будет удалён. Превью-файл также будет удалён.»
- После удаления: `level-XXX-thumb.webp` удаляется, `order` остальных пересчитывается, `audit.log` → `level.delete`.

### 3.5. Переупорядочивание

Drag&drop строк таблицы. После drop — `order` пересчитывается 1..N; сохранение — атомарное.

### 3.6. Валидация всех уровней

Кнопка «Валидировать все уровни» — прогон каждого уровня по всем правилам §10. Отчёт:

```
┌─ Валидация уровней ─────────────────────────────────────┐
│  ✅ Уровень 1 «Вход в шахту» — ok                       │
│  ❌ Уровень 2 «Лестничный колодец»:                      │
│     • нет элемента с ролью start                         │
│  ⚠ Уровень 3 «Лагерь шерифа»:                            │
│     • 1 pickup (рекомендуется ≥ 3 для Lode Runner)      │
│                                                          │
│  Итого: 1 ok, 1 ошибка, 1 предупреждение                │
└──────────────────────────────────────────────────────────┘
```

Ошибки блокируют публикацию игры (блок 7, §2.2). Предупреждения — не блокируют.

---

## 4. UI: редактор уровня

### 4.1. Layout

```
┌──────────────────────────────────────────────────────────────────────┐
│ [← К уровням]  Уровень 1: [Вход в шахту____]   [Тест]  [Сохранить] │
├────────────┬──────────────────────────────────────┬──────────────────┤
│ ПАЛИТРА    │  ВИЗУАЛЬНЫЙ GRID                     │  ПРЕДПРОСМОТР    │
│ ЛЕГЕНДЫ    │                                      │                  │
│            │  ┌────────────────────────────────┐  │  ┌────────────┐  │
│ ▾ Фон      │  │ 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱    │  │  │            │  │
│   🟩       │  │ 🧱 🟩 🟩 🟩 🏹 🟩 🟩 🟩 🧱    │  │  │  [canvas]  │  │
│ ▾ Преграда │  │ 🧱 🟩 🟩 🟩 🟩 🟩 🦹 🟩 🧱    │  │  │            │  │
│   🧱       │  │ 🧱 🧱 🚪 🧱 🧱 🧱 🧱 🧱 🧱    │  │  └────────────┘  │
│   🌳       │  └────────────────────────────────┘  │                  │
│ ▾ Моб      │                                      │  Легенда:        │
│   🦹       │  Строк: 24 × 32    [100%] [150%]    │  🧱 преграда     │
│ ▾ Предмет  │  [↻ Undo] [↻ Redo]                   │  🟩 фон          │
│   🔑       │                                      │  🏹 старт        │
│ ▾ Выход    │  ── Режим ──                         │  🦹 моб          │
│   🚪       │  (•) Визуально  ( ) Текст            │  🔑 предмет      │
│ ▾ Старт    │                                      │  🚪 выход        │
│   🏹       │  ── Мини-карта ──                    │                  │
│ ▾ Снаряд   │  ┌────────────────────────────────┐  │  Параметры:      │
│   ➡️       │  │ [32×24 миниатюра]              │  │  time_limit [120]│
│            │  └────────────────────────────────┘  │                  │
│ [Редактировать легенду]                           │                  │
└────────────┴──────────────────────────────────────┴──────────────────┘
```

**Три колонки:**

1. **Палитра легенды** (слева) — emoji по ролям.
2. **Редактор** (центр) — визуальный grid (основной) или textarea (bulk edit).
3. **Предпросмотр** (справа) — статический рендер + легенда + параметры.

### 4.2. Визуальный grid

**Canvas** с сеткой ячеек, размер ячейки 24 px (настраивается 100%/150%/200%).

- Каждая ячейка: если emoji — PUA-кодпоинт → `drawImage` из `bankMap`; иначе `fillText`.
- **Сетка с подсветкой клеток** (опционально).
- **Прокрутка** для больших уровней (`vertical-scroll`, `horizontal-scroll`).
- **Виртуализация**: рендерятся только видимые ячейки.
- **Мини-карта** для больших уровней — обзор всего мира с рамкой текущего окна.

**Инструменты (toolbar):**

| Инструмент | Горячая клавиша | Действие |
|---|---|---|
| Кисть | B | Клик — вставить выбранный emoji |
| Ластик | E | Клик — вставить emoji с ролью `background` |
| Прямоугольник | R | Залить выделенный прямоугольник выбранным emoji |
| Заливка | G | Flood fill |
| Выбор | V | Выделение для копирования/вставки |

**Выбор emoji:** клик по emoji в палитре → «берёт кисть». Текущая кисть подсвечена в палитре и показана в статусбаре.

**Размещение композита:**

- Клик по ячейке → весь композит (все `N` клеток) заполняется спрайтами из `composite.sprites`.
- При наведении на клетку, где поместится композит, — **полупрозрачное превью** всех `N` клеток.
- **Выход за границы** — клик игнорируется; подсветка красным.
- **Перекрытие** с существующим композитом или `solid`-объектом — модалка «Перезаписать? Да / Нет».

**Удаление:**

- Правый клик по любой клетке композита → удаляется весь композит.
- Правый клик по 1×1-объекту → удаляется эта клетка (вставляется emoji-пустота).

**Undo/Redo:**

- Стек до 100 операций.
- Ctrl+Z / Ctrl+Y.
- Каждое размещение/удаление/заливка — одна операция.

**Синхронизация с textarea:**

- Любое изменение в визуальном режиме моментально обновляет textarea.
- Любое изменение в textarea (после валидации) обновляет визуальный режим.
- Внутренняя модель — `string[][]` (массив строк emoji).

### 4.3. Палитра легенды

Слева, сгруппирована по роли:

```
▾ Фон (1)              [🟩]
▾ Преграда (2)         [🧱] [🌳]
▾ Коллайдер (1)        [🗿]
▾ Моб (1)              [🦹]
▾ Предмет (2)          [🔑] [💎]
▾ Выход (1)            [🚪]
▾ Старт (1)            [🏹]
▾ Снаряд (1)           [➡️]
▾ Декор (0)
```

- **Источник** — эффективная легенда: `{...genre.default_legend, ...game.legend}`.
- **Наследованные emoji** (только из жанра) — с пометкой «из жанра» мелким шрифтом.
- **Композиты** — с бейджем размера (`[3×2]`).
- **Анимации** — с бейджем «▶».
- **Клик** — «берёт кисть».
- **Кнопка «Редактировать легенду»** — переход на вкладку «Легенда».
- Если легенда пуста — «Легенда пуста. Перейдите на вкладку „Легенда"» с кнопкой-ссылкой.

### 4.4. Текстовый режим (bulk edit)

**Формат:** textarea с моноширинным шрифтом. Каждая строка — emoji через **один пробел**.

**Особенности:**

- **Кастомные эмодзи** (PUA-кодпоинты) отображаются как `[e001]` (ID из банка). Это позволяет редактировать текст без «тофу».
- **Copy as text** — при копировании кастомный заменяется на `[e001]`.
- **Paste as text** — `[e001]` распознаётся и подставляется PUA-кодпоинт.
- **Анимации** — тоже как `[e020]`.

**Валидация в реальном времени** (debounce 300 мс):

- **Строки одинаковой длины** — если нет, подсветка «висящей» строки красным, сообщение «Строка N короче/длиннее остальных».
- **Каждый emoji известен в эффективной легенде** — неизвестные emoji подсвечиваются жёлтым, сообщение «Emoji X не в легенде. Добавить в легенду?» с кнопкой.
- **Размер** — соответствует движку; иначе ошибка.
- **`requires_start`** — ровно один `start`; если 0 или >1 — сообщение.
- **`requires_exit`** — ≥ 1 `exit`.
- **`min_pickups`** — ≥ N `pickup`.

**Ошибки не блокируют ввод, но блокируют сохранение.**

**Кнопки:**

- **«+ Строка»** / **«− Строка»** — только для `vertical-scroll` (для `static-screen` и `horizontal-scroll` — disabled).
- **«+ Столбец»** / **«− Столбец»** — только для `horizontal-scroll`.
- **«Очистить»** — модалка подтверждения.
- **«Заполнить по умолчанию»** — создаёт сетку по размеру движка, заполненную emoji-пустотой; `start` — в левом нижнем углу, `exit` — в правом нижнем (если `level_rules` требует).

**Отображение размеров:** «Строк: 24 × 32. Требуется: 24×32 (static-screen)».

### 4.5. Предпросмотр

Canvas 400×300 справа:

- Рендер emoji-сетки как карта: цвет фона ячейки по роли (серый — фон, синий — преграда, оранжевый — коллайдер, красный — моб, жёлтый — предмет, зелёный — выход, белый — старт, фиолетовый — снаряд, голубой — декор).
- Emoji отрисовывается мелко поверх цвета (опционально).
- **Клик по ячейке** → highlight соответствующей позиции в редакторе.
- **Hover** → tooltip с emoji, role, name.
- **Легенда снизу** — мини-таблица «emoji → role → name».

**Предпросмотр — не игровой**, только визуализация структуры. Игровой предпросмотр — блок 11.

### 4.6. Мини-карта

Для `vertical-scroll` и `horizontal-scroll` — миниатюра всего мира (например, 200×60 или 60×200) внизу под редактором:

- Все клетки — цветом по роли.
- Рамка текущего окна.
- Клик по мини-карте — прокрутка к позиции.

### 4.7. Параметры уровня

Справа под предпросмотром (или отдельной вкладкой):

```
┌─ Параметры уровня ──────────────────────────────────────┐
│  time_limit_seconds  [120]  (0 = без лимита)           │
│                                                          │
│  ── Чекпоинты (horizontal-scroll) ──                    │
│  ☐ Использовать                                         │
│   [50,10] [120,10]  [+ Добавить]                       │
│                                                          │
│  ── Триггеры ──                                         │
│  3 триггера  [Редактировать]                           │
│                                                          │
│  ── Условия победы ──                                   │
│  ☑ Достичь выхода                                       │
│  ☐ Убить всех [🐉 ▼]                                    │
│  ☐ Собрать все [gold ▼]                                 │
│                                                          │
│  ── Условия поражения ──                                │
│  ☑ HP = 0                                               │
│  ☑ Жизни = 0                                            │
│  ☐ Таймер истёк                                         │
└──────────────────────────────────────────────────────────┘
```

**Триггеры** — редактируются в отдельной модалке (см. §6). **Условия победы/поражения** — блок 10, §5.

### 4.8. Автосохранение черновика

Каждые 30 секунд активный уровень сериализуется в `localStorage` под ключом `draft:level:{game_id}:{level_id}` (или `draft:level:{game_id}:new`).

При открытии уровня:

- Если в `localStorage` есть черновик новее сохранённого (по `saved_at`) — модалка «Найден несохранённый черновик от HH:MM. Восстановить?» → «Восстановить» / «Игнорировать и загрузить сохранённое».
- После успешного сохранения — уровень из `localStorage` удаляется.

При закрытии вкладки с несохранённым черновиком — `beforeunload` предупреждение.

### 4.9. Горячие клавиши

| Клавиша | Действие |
|---|---|
| Ctrl+Z | Undo |
| Ctrl+Y / Ctrl+Shift+Z | Redo |
| Ctrl+S | Сохранить |
| B / E / R / G / V | Инструменты |
| 1–9 | Выбор emoji из палитры по индексу |
| Esc | Отмена модалки / «нет инструмента» |
| Пробел (удержание) | Панорамирование (для больших уровней) |

---

## 5. Сохранение уровня

### 5.1. Кнопка «Сохранить»

1. **Клиентская валидация** — та же, что в реальном времени (§10.1). Если есть ошибки — модалка «Уровень содержит ошибки. Исправить или сохранить как черновик?». **Сохранение как черновик** — записывает уровень (с пометкой `draft: true`; отображение в списке — бейдж «черновик»).
2. **Серверная валидация** — перепроверяет всё заново (§10).
3. **Генерация превью** — на клиенте: отрисовка уровня в offscreen canvas 320×240 (масштабирование по размеру сетки) → `toDataURL('image/webp', 0.8)` → base64 → POST на `/admin/games/{id}/levels/{level_id}/thumb`.
4. **Сервер** — декодирует, ре-энкодирует в WebP, сохраняет `media/{game-id}/{level_id}-thumb.webp`, обновляет `levels[i].thumb`.
5. **Обновление `updated_at`**.
6. **Очистка `localStorage`** для этого уровня.

**Ошибка на любом шаге** — уровень не сохраняется; выдаётся сообщение с причиной.

### 5.2. Превью уровня

- Размер: 320×240 (4:3).
- Формат: WebP q=80 (фолбэк PNG, если браузер не поддерживает WebP).
- Анимированные эмодзи — отображаются **первым кадром**.
- Композиты — видны целиком.
- Невидимые элементы (чекпоинты, триггеры) — не отображаются.
- **Серверная проверка:** base64 декодируется, размер ≤ 500 KB, MIME `image/webp` или `image/png`, размеры 320×240. Сервер **игнорирует** поле `thumb` из формы; генерирует путь сам.

### 5.3. Кнопка «Тест»

Открывает `/play/{slug}/{level_id}` в новой вкладке — **сохранённую версию** уровня. Несохранённые изменения не учитываются. Для отладки несохранённых изменений — кнопка «Сохранить и протестировать».

---

## 6. UI: триггеры уровня

Триггеры редактируются в отдельной модалке (детальная схема — блок 10, §4). На вкладке уровня — только сводка и кнопка «Редактировать».

```
┌─ Триггеры уровня ───────────────────────────────────────┐
│  [+ Создать триггер]   [▶ Проверить триггеры]          │
├──────────────────────────────────────────────────────────┤
│  ≡ trig-001  Убить всех роботов      [✓] [✎] [🗑]      │
│  ≡ trig-002  Таймер 60 сек           [✓] [✎] [🗑]      │
│  ≡ trig-003  Найти секрет            [☐] [✎] [🗑]      │
├──────────────────────────────────────────────────────────┤
│  Использовано: 3 из 50                                   │
└──────────────────────────────────────────────────────────┘
```

**Форма триггера** — блок 10, §4.

**«Проверить триггеры»** — предпросмотр уровня с отладочной визуализацией (список триггеров, лог срабатываний) — блок 10, §4.5.

---

## 7. UI: условия победы/поражения

Редактируются в блоке «Параметры уровня» (см. §4.7). Детальная схема — блок 10, §5.

---

## 8. Валидация

### 8.1. Структурная

| Правило | Проверка |
|---|---|
| `name` 1–80 | `mb_strlen` |
| `level_id` `^level-\d{3,}$`, уникален в игре | Regex + проверка массива |
| `order` — целое, уникально в игре | Пересчитывается при сохранении/удалении |
| `grid` — массив непустых строк | `count > 0` |
| Строки одинаковой длины | `grapheme_strlen` каждой |
| Каждый emoji — один графемный кластер | `grapheme_strlen(cell) === 1` |
| Размеры по движку | `static-screen`: 24×32; `vertical-scroll`: N×32; `horizontal-scroll`: 24×M |
| Каждый emoji известен в эффективной легенде | Поиск в `{...default, ...legend}` |
| `time_limit_seconds` — целое ≥ 0 | Числовое |
| `checkpoints` — каждый в пределах | `0 ≤ col < M`, `0 ≤ row < 24` |

### 8.2. Семантическая (по `level_rules` жанра)

| Правило | Проверка |
|---|---|
| `requires_start` → ровно один `start` | `count(role=start) === 1` |
| `requires_exit` → ≥ 1 `exit` | `count(role=exit) ≥ 1` |
| `requires_finish` → ≥ 1 `exit` | Для `horizontal-scroll` |
| `min_pickups` → ≥ N `pickup` | `count(role=pickup) ≥ N` |

### 8.3. Композиты

| Правило | Проверка |
|---|---|
| Прямоугольник композита в границах сетки | `anchor.r + height ≤ ROWS`, `anchor.c + width ≤ COLS` |
| Нет перекрытий композитов | Прямоугольники не пересекаются |
| Body-спрайты соответствуют `composite.sprites` | Каждая клетка `(r+i, c+j)` = `sprites[i][j]` |
| Body-спрайты вне композитов — валидные `1×1`-элементы | Если встретился body вне композита и нет `1×1`-элемента с этим emoji — ошибка |

### 8.4. Превью

- Превью обязательно при сохранении.
- Сервер: base64 декодируется, размер ≤ 500 KB, MIME `image/webp`/`image/png`, размеры 320×240.

### 8.5. Поведение при ошибке

- **Клиентская валидация** — ошибки подсвечиваются; сохранение блокируется (кроме «сохранить как черновик»).
- **Серверная валидация** — при ошибке — flash `error` с причиной; уровень не изменяется.
- **Валидация перед запуском** (блок 11) — повторная проверка при `/play/...`; при ошибке — страница ошибки.

---

## 9. Безопасность

| Угроза | Мера |
|---|---|
| Подделка emoji-сетки через POST | Сервер перевалидирует каждый графемный кластер и известность в легенде |
| XSS через emoji (HTML-тег в клетке) | Проверка, что emoji — графемный кластер; HTML-теги отклоняются |
| XSS через `name` уровня | `htmlspecialchars()` при выводе |
| Path traversal через `level_id` | Regex `^level-\d{3,}$` |
| Path traversal через `thumb` (подмена пути) | Сервер игнорирует `thumb` из формы; генерирует путь сам |
| Загрузка вредоносного «превью» | Только base64 из POST; сервер декодирует и ре-энкодирует в WebP/PNG через GD; MIME проверяется |
| Переполнение размера | Сетка ≤ 32×24 / 500×32 / 24×800; base64 ≤ 500 KB |
| CSRF | Токен из блока 3 |
| Гонка при параллельном редактировании | Атомарная запись `FileStore`; `updated_at` для детекции; `localStorage`-черновик минимизирует потери |
| Обход `level_rules` подделкой `genre_config` | `genre_config` берётся с сервера из `genres.json`, не из формы |
| Исполнение PHP в `/media/{game-id}/` | `php_flag engine off` (блок 3) |

---

## 10. Аудит

```json
{"ts":"...","user":"admin","action":"level.create","target":"game-001","details":{"level_id":"level-001","name":"Вход в шахту","grid":"32x24"}}
{"ts":"...","user":"admin","action":"level.update","target":"game-001","details":{"level_id":"level-001","changed":["grid","params.time_limit_seconds"]}}
{"ts":"...","user":"admin","action":"level.thumb_update","target":"game-001","details":{"level_id":"level-001","format":"webp","bytes":28451}}
{"ts":"...","user":"admin","action":"level.clone","target":"game-001","details":{"from":"level-001","to":"level-002"}}
{"ts":"...","user":"admin","action":"level.reorder","target":"game-001","details":{"order":["level-002","level-001","level-003"]}}
{"ts":"...","user":"admin","action":"level.delete","target":"game-001","details":{"level_id":"level-003"}}
{"ts":"...","user":"admin","action":"level.trigger_create","target":"game-001","details":{"level_id":"level-001","trigger_id":"trig-001"}}
{"ts":"...","user":"admin","action":"level.victory_conditions_update","target":"game-001","details":{"level_id":"level-001","conditions":["reach_exit","kill_all:🐉"]}}
```

---

## 11. Критерии приёмки блока 8

### 11.1. Список уровней

1. **Вкладка «Уровни» активна.** В карточке игры вкладки «Основное», «Легенда», «Уровни» активны; «Управление», «Настройки» — активны после блоков 9.
2. **Пустой список.** Новая игра имеет `levels: []`; «Пока нет уровней».
3. **Создание.** `+ Создать уровень` → модалка; сетка заполняется emoji-пустотой по размеру движка.
4. **Клонирование.** 📋 → новый `level_id`, имя «Копия: X», новый `order`; `thumb` генерируется при первом сохранении.
5. **Переупорядочивание.** Drag&drop меняет порядок; после drop — `order` пересчитан 1..N.
6. **Удаление.** 🗑 требует ввода `name`; после удаления `thumb` удалён, `order` пересчитан, `audit.log` → `level.delete`.
7. **Валидация всех.** «Валидировать все уровни» — отчёт с ok/ошибки/предупреждения.

### 11.2. Редактор уровня

8. **Визуальный grid** — основной режим; ячейки, эмодзи через `drawImage`/`fillText`.
9. **Палитра легенды** — emoji по ролям; композиты с бейджем размера; анимации с «▶»; наследованные — с пометкой «из жанра».
10. **Выбор кисти.** Клик по emoji в палитре — «берёт кисть»; текущая кисть подсвечена.
11. **Размещение 1×1.** Клик по клетке — вставка emoji.
12. **Размещение композита.** Клик по якорю композита → все `N` клеток заполняются спрайтами. При наведении — полупрозрачное превью.
13. **Выход за границы.** Попытка разместить композит за границей — игнорируется с подсветкой.
14. **Перекрытие.** Попытка разместить поверх существующего — модалка «Перезаписать?».
15. **Удаление композита.** Правый клик по любой клетке → весь композит удалён.
16. **Undo/Redo.** Ctrl+Z / Ctrl+Y; до 100 операций.
17. **Текстовый режим.** Переключение визуального и текстового режимов; синхронизация.
18. **Кастомные emoji в textarea.** PUA-кодпоинты отображаются как `[e001]`; paste `[e001]` → PUA.
19. **Валидация в реальном времени.** Неравные строки, неизвестный emoji, ошибки `requires_*` — подсвечиваются.
20. **Параметры.** `time_limit_seconds` сохраняется в `params.time_limit_seconds`; чекпоинты — для `horizontal-scroll`; триггеры и условия — модалки.
21. **Автосохранение.** Через 30 сек — черновик в `localStorage`; `beforeunload`; при возврате — модалка восстановления.
22. **Горячие клавиши** — работают.

### 11.3. Размеры по движку

23. **`static-screen`.** Сетка ровно 24×32; кнопки «± Строка/± Столбец» disabled. Попытка сохранить 10×5 — ошибка.
24. **`vertical-scroll`.** Сетка N×32; «+ Строка» расширяет; до 500. «+ Столбец» disabled.
25. **`horizontal-scroll`.** Сетка 24×M; «+ Столбец» расширяет; до 800. «+ Строка» disabled.
26. **Мини-карта** — для `vertical-scroll`/`horizontal-scroll`.

### 11.4. Неизвестный emoji

27. Ввод `🚀` (нет в легенде) в textarea → жёлтая подсветка, предупреждение с кнопкой «Добавить в легенду». При сохранении без добавления — блокировка сервером.

### 11.5. Семантическая валидация

28. **`requires_start`.** Сетка без `start` — ошибка. С двумя — ошибка. С одним — сохранение проходит.
29. **`requires_exit`.** Сетка без `exit` — ошибка.
30. **`min_pickups`.** Меньше N — ошибка.
31. **Размеры.** Попытка сохранить неверный размер — ошибка.

### 11.6. Композиты

32. **Прямоугольник в границах.** Попытка разместить 3×2 в правом нижнем углу (выходит за границу) — игнорируется.
33. **Перекрытие.** Два композита не могут перекрываться.
34. **Body-спрайты.** Правка textarea, ломающая композит → ошибка «(2, 3) = 🏰, ожидалось 🏯».
35. **Body вне композита.** Ввод body-спрайта вручную вне композита → ошибка.

### 11.7. Сохранение и превью

36. **Сохранение.** Создаёт `levels[i]` в JSON, генерирует `thumb` 320×240 WebP на клиенте, отправляет на сервер, сохраняет в `media/{game-id}/{level_id}-thumb.webp`, обновляет `updated_at`.
37. **Превью-файлы доступны.** `/media/{game-id}/level-001-thumb.webp` отдаётся.
38. **Анимации в превью** — отображаются первым кадром.
39. **Композиты в превью** — видны целиком.

### 11.8. Тест

40. **Кнопка «Тест».** Открывает `/play/{slug}/{level_id}` в новой вкладке с сохранённой версией.

### 11.9. Триггеры и условия

41. **Модалка триггеров.** Список, форма, «Проверить триггеры» — из блока 10.
42. **Условия победы/поражения** — редактируются в параметрах; схема — блок 10.

### 11.10. Безопасность и аудит

43. **CSRF.** POST без токена — 403.
44. **Подмена `thumb`.** Сервер игнорирует `thumb` из формы.
45. **XSS в `name`** — экранировано.
46. **XSS в emoji** — HTML-тег отклонён.
47. **Аудит.** Все операции записаны в `audit.log`.

### 11.11. Обратная совместимость

48. **Критерии блоков 1–7, 10** выполняются.

---

## 12. Оценка

**Ориентир:** 4–5 человеко-дней на разработчика (или ~1,5 рабочих дня с ИИ-ассистентом).

Основное время:

- **Визуальный grid** — Canvas, кисть, drag, undo/redo, виртуализация (≈1,5 дня).
- **Синхронизация с textarea** — конвертация `[e001]` ↔ PUA, валидация в реальном времени (≈0,5 дня).
- **Композиты в сетке** — размещение, превью, перекрытия, удаление целиком (≈0,5 дня).
- **Палитра легенды** — группировка, композиты, анимации (≈0,3 дня).
- **Предпросмотр** — статический рендер, hover, клик (≈0,3 дня).
- **Мини-карта** (≈0,2 дня).
- **Параметры + триггеры + условия** — модалки (≈0,5 дня).
- **Автосохранение в localStorage** (≈0,2 дня).
- **Генерация превью** — offscreen canvas, `toDataURL`, передача на сервер (≈0,3 дня).
- **Клонирование, переупорядочивание, удаление** (≈0,3 дня).
- **Валидация** — 25+ правил (≈0,5 дня).
- **Отладка и тестирование** (≈0,5 дня).

---

## 13. Что не делаем в блоке 8 и почему

- **Не реализуем игровой предпросмотр с движком** — блок 11. На этом шаге — только статический рендер.
- **Не реализуем полный тест проходимости** (достижим ли `exit` от `start`) — блок 13 (`validate-levels.php`). На этом шаге — только базовая проверка наличия `start`/`exit`/`min_pickups`.
- **Не реализуем специфичные подсказки `params`** для движков (`fuel_capacity`, `seed`, `hole_duration_ms`) — блок 11. На этом шаге — произвольный JSON.
- **Не реализуем массовые операции с уровнями** — блок 12.
- **Не реализуем публичный запуск уровня** — блок 11.
- **Не реализуем drag&drop для выбора emoji из палитры** (только клик) — упрощение.
- **Не реализуем зум сетки в предпросмотре** — фиксированный 400×300.
- **Не реализуем «импорт уровня из JSON-файла»** — блок 12 (опционально).
- **Не реализуем «редактор маршрутов патруля» и «AI-зоны»** — блок 10.
- **Не реализуем полноценную проверку проходимости** (BFS/DFS по графу) — блок 13.
- **Не реализуем «сохранение черновика на сервере»** — только `localStorage`; серверное сохранение — только явное «Сохранить».

---

**Конец блока 8 из 13.**

Следующий блок — **Управление, звуки, настройки движка** (назначение клавиш, параметры движка, до 10 бипер-звуков, копирование настроек между играми).


# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 9. Управление, звуки, настройки движка

## 1. Общее описание

Три взаимосвязанные подсистемы на уровне **игры**:

1. **Управление** (`game.controls`) — назначение клавиш семантическим действиям движка.
2. **Звуки** (`game.sounds`) — до 10 бипер-звуков на события движка.
3. **Настройки движка** (`game.engine_overrides`) — переопределения параметров `engine_config` жанра: скорость, физика, камера, громкость и т. д.

**Наследование от жанра:**

- `controls = genre.default_controls` при создании игры.
- `sounds = genre.default_sounds` при создании игры.
- `engine_overrides = {}` при создании; значения берутся из `genre.engine_config`.

**Редактирование:** на вкладках «Управление» и «Настройки» карточки игры (блок 7). До блока 9 эти вкладки — read-only с дефолтами жанра.

**Часть настроек редактируется на вкладке «Настройки», но описана в других блоках:**

- `player_attacks` (атаки игрока) — блок 10, §2.1.
- `player.lives`, `player.health_max`, `player.dodge`, `player.parry` — блок 10, §2.3.
- `scoring` (очки, комбо, time bonus) — блок 10, §2.6.
- `player_attacks.special` — блок 10, §2.1.

Настоящий блок покрывает: **`controls`**, **`sounds`**, и **`engine_overrides` без боевых полей** (общие, физика, камера, специфичные для движков).

**Атомарное сохранение:** все три поля сохраняются вместе с остальными вкладками карточки игры по единой кнопке «Сохранить» (блок 7).

---

## 2. Схема данных

### 2.1. `controls` в карточке игры

```json
"controls": {
  "left":       ["ArrowLeft",  "KeyA"],
  "right":      ["ArrowRight", "KeyD"],
  "up":         ["ArrowUp",    "KeyW"],
  "down":       ["ArrowDown",  "KeyS"],
  "jump":       ["Space",      "KeyZ"],
  "fire":       ["KeyX"],
  "dig_left":   ["KeyQ"],
  "dig_right":  ["KeyE"],
  "action":     ["Enter"],
  "pause":      ["Escape"],
  "start":      ["Enter"]
}
```

**Правила:**

| Аспект | Правило |
|---|---|
| Ключ | действие (см. §2.2) |
| Значение | массив `KeyboardEvent.code`, **1–2 элемента** |
| Код клавиши | regex из §2.5 |
| Действие | должно быть в списке обязательных/опциональных для движка игры |
| Пустой массив | не допускается для обязательных действий |
| Дубликат клавиши между действиями | разрешён, но подсвечивается предупреждением |

### 2.2. Действия по движкам

Набор действий определяется **сервером** по движку игры и её легенде.

**`static-screen`:**

| Действие | Категория | Условие |
|---|---|---|
| `left`, `right`, `up`, `down` | обязательное | всегда |
| `pause`, `start` | обязательное | всегда |
| `fire` | опциональное | есть `player_attacks.melee` или `player_attacks.ranged` (блок 10) |
| `jump` | опциональное | `physics.jump_power < 0` и `movement_type === gravity` |
| `dig_left`, `dig_right` | опциональное | в легенде есть `obstacle + diggable` |
| `action` | опциональное | есть `player_attacks.special.enabled` или `collider.actions[open]` |

**`vertical-scroll`:**

| Действие | Категория |
|---|---|
| `left`, `right`, `up`, `down` | обязательное |
| `fire` | обязательное (основная механика) |
| `pause`, `start` | обязательное |

**`horizontal-scroll`:**

| Действие | Категория |
|---|---|
| `left`, `right`, `up`, `down` | обязательное |
| `jump` | обязательное |
| `fire` | обязательное |
| `pause`, `start` | обязательное |

**Неактивные действия сохраняются в JSON** (на случай возврата легенды/настроек), но в UI не отображаются. При сохранении **не удаляются**.

### 2.3. `sounds` в карточке игры

```json
"sounds": [
  {
    "event": "dig",
    "wave": "square",
    "frequency_hz": 440,
    "frequency_end_hz": null,
    "duration_ms": 50,
    "volume": 0.03,
    "envelope": "decay"
  },
  {
    "event": "pickup",
    "wave": "square",
    "frequency_hz": 1320,
    "frequency_end_hz": null,
    "duration_ms": 60,
    "volume": 0.04,
    "envelope": "pluck"
  },
  {
    "event": "death",
    "wave": "sawtooth",
    "frequency_hz": 220,
    "frequency_end_hz": 55,
    "duration_ms": 400,
    "volume": 0.06,
    "envelope": "linear"
  }
]
```

**Правила:**

| Поле | Правило |
|---|---|
| `sounds.length` | ≤ **10** |
| `event` | одно из 10 событий (см. §2.4), уникально в списке |
| `wave` | `square` \| `triangle` \| `sawtooth` \| `noise` |
| `frequency_hz` | 20–4000 |
| `frequency_end_hz` | `null` или 20–4000 (глиссандо) |
| `duration_ms` | 10–1000 |
| `volume` | 0.0–0.3 |
| `envelope` | `linear` \| `decay` \| `pluck` |

**Событие без записи в `sounds[]`** — не играет (без ошибки). **Движок использует только те события, которые для него релевантны** (см. §2.4).

### 2.4. Десять событий звуков

| Событие | Когда срабатывает | Применимо к движкам |
|---|---|---|
| `jump` | Прыжок игрока | `static-screen` (gravity), `horizontal-scroll` |
| `pickup` | Подбор предмета | все |
| `dig` | Копание ямы | `static-screen` (при `diggable`) |
| `hit` | Попадание по игроку или мобу | все |
| `shoot` | Выстрел игрока или моба | `vertical-scroll`, `horizontal-scroll`, `static-screen` (при `fire`) |
| `death` | Гибель игрока | все |
| `win` | Победа / уровень пройден | все |
| `open` | Открытие двери / активация коллайдера | все |
| `enemy_trapped` | Враг в яме | `static-screen` (при `diggable`) |
| `level_start` | Начало уровня | все |

### 2.5. Допустимые `KeyboardEvent.code`

```
ArrowUp | ArrowDown | ArrowLeft | ArrowRight
KeyA ... KeyZ
Digit0 ... Digit9
Space | Escape | Enter | Tab
Backquote | Minus | Equal | BracketLeft | BracketRight
Backslash | Semicolon | Quote | Comma | Period | Slash
ShiftLeft | ShiftRight | ControlLeft | ControlRight | AltLeft | AltRight
F1 ... F12
Numpad0 ... Numpad9
```

**Не допускаются как единственная клавиша действия:**

- `ShiftLeft`, `ShiftRight`, `ControlLeft`, `ControlRight`, `AltLeft`, `AltRight`, `MetaLeft`, `MetaRight` — модификаторы-обёртки (конфликт с браузером/ОС).

**Модификаторы могут быть дополнительной клавишей** (вторая в массиве), но не единственной.

### 2.6. `engine_overrides` в карточке игры

Переопределения `genre.engine_config`. Все поля опциональны; если поле отсутствует — берётся из `engine_config` жанра.

```json
"engine_overrides": {
  "speed_scale": 1.1,
  "sprite_flicker": true,
  "sprite_flicker_intensity": 0.12,
  "input_responsiveness": 0.9,
  "sound_volume": 0.04,
  "sound_enabled": true,
  "fps": 60,
  "show_hud": true,
  "animation_speed_scale": 1.0,
  "physics": {
    "jump_power": -5.5,
    "gravity": 0.4
  },
  "combat": {
    "invulnerability_ms": 1200
  },
  "camera_mode": "follow",
  "camera_lerp": 0.2,
  "hole_duration_ms": 2500
}
```

### 2.7. Полный перечень настраиваемых полей

**Общие (все движки):**

| Поле | Тип | Диапазон | Default |
|---|---|---|---|
| `speed_scale` | number | 0.5–2.0 | 1.0 |
| `sprite_flicker` | bool | — | false |
| `sprite_flicker_intensity` | number | 0.0–1.0 | 0.1 |
| `input_responsiveness` | number | 0.0–1.0 | 1.0 |
| `sound_volume` | number | 0.0–0.3 | 0.03 |
| `sound_enabled` | bool | — | true |
| `fps` | enum | 30 \| 60 | 60 |
| `show_hud` | bool | — | true |
| `animation_speed_scale` | number | 0.5–2.0 | 1.0 |

**`physics` (все движки, разные поля):**

| Поле | Тип | Диапазон | Для движков |
|---|---|---|---|
| `physics.gravity` | number | 0.0–2.0 | `static-screen` (gravity), `horizontal-scroll` |
| `physics.move_speed` | number | 0.5–10.0 | все |
| `physics.jump_power` | number | -10.0–0.0 | `static-screen` (gravity), `horizontal-scroll` |
| `physics.climb_speed` | number | 0.5–3.0 | `static-screen` |
| `physics.swim_speed` | number | 0.5–3.0 | `static-screen` |
| `physics.coyote_time_ms` | number | 0–300 | `static-screen` (gravity), `horizontal-scroll` |
| `physics.jump_buffer_ms` | number | 0–300 | `static-screen` (gravity), `horizontal-scroll` |
| `physics.diagonal_movement` | bool | — | `static-screen` (top_down) |
| `physics.max_fall_speed` | number | 4.0–20.0 | `static-screen` (gravity), `horizontal-scroll` |
| `physics.variable_jump` | bool | — | `horizontal-scroll` |
| `physics.double_jump` | bool | — | `horizontal-scroll` |
| `physics.air_control` | number | 0.0–1.0 | `horizontal-scroll` |
| `physics.vertical_move_speed` | number | 0.5–10.0 | `vertical-scroll` |
| `physics.vertical_range` | number | 1–15 | `vertical-scroll` |
| `physics.boundary_behavior` | enum | `clamp` \| `wrap` | `vertical-scroll` |

**Камера и скролл:**

| Поле | Тип | Диапазон | Для движков |
|---|---|---|---|
| `camera_mode` | enum | `follow` \| `room` \| `fixed` | `horizontal-scroll` |
| `camera_lerp` | number | 0.05–0.5 | `horizontal-scroll` |
| `scroll_direction` | enum | `up` \| `down` | `vertical-scroll` |
| `scroll_speed` | number | 0.5–10.0 | `vertical-scroll` |
| `world_generation` | enum | `grid` \| `procedural` | `vertical-scroll` |

**Базовый `combat` (без player_attacks):**

| Поле | Тип | Диапазон | Для движков |
|---|---|---|---|
| `combat.invulnerability_ms` | number | 0–5000 | все |
| `combat.enemy_contact_damage` | number | 0–10 | все |
| `combat.player_melee_cooldown_ms` | number | 100–2000 | `static-screen`, `horizontal-scroll` |
| `combat.player_fire_rate_ms` | number | 50–2000 | `vertical-scroll` |

**Специфичные для `static-screen`:**

| Поле | Тип | Диапазон | Условие |
|---|---|---|---|
| `hole_duration_ms` | number | 500–10000 | есть `obstacle + diggable` |
| `trap_duration_ms` | number | 500–5000 | есть `obstacle + diggable` |
| `movement_type` | enum | `gravity` \| `top_down_4dir` \| `top_down_8dir` \| `top_down_4dir_no_diag` | всегда |
| `view_mode` | enum | `side` \| `top` | всегда |

**Специфичные для `vertical-scroll`:**

| Поле | Тип | Диапазон |
|---|---|---|
| `player.fuel_capacity` | number | 0–1000 (0 = выключено) |
| `player.fuel_consumption_per_sec` | number | 0.0–20.0 |

**Не входят в блок 9 (описаны в блоке 10):**

- `player.lives`, `player.health_max`, `player.dodge`, `player.parry`.
- `player_attacks.*`.
- `scoring.*`.

Эти поля редактируются на вкладке «Настройки», но их схема и семантика — блок 10.

---

## 3. UI: вкладка «Управление»

### 3.1. Layout

```
┌─ Основное ─┬─ Легенда ─┬─ Уровни ─┬─ Управление ─┬─ Настройки ─┐
│            │           │          │  (активна)   │             │
└────────────┴───────────┴──────────┴──────────────┴─────────────┘
┌─ Управление ────────────────────────────────────────────────────┐
│  Движок: static-screen (Lode Runner)                            │
│                                                                 │
│  ┌─ Действие ─────┬─ Клавиши ────────────────────────────────┐ │
│  │ Влево           │ [←] [A]                       [✎] [✕]   │ │
│  │ Вправо          │ [→] [D]                       [✎] [✕]   │ │
│  │ Вверх           │ [↑] [W]                       [✎] [✕]   │ │
│  │ Вниз            │ [↓] [S]                       [✎] [✕]   │ │
│  │ Копать влево    │ [Q]                           [✎] [✕]   │ │
│  │ Копать вправо   │ [E]                           [✎] [✕]   │ │
│  │ Пауза           │ [Esc]                         [✎] [✕]   │ │
│  │ Старт           │ [Enter]                       [✎] [✕]   │ │
│  └─────────────────┴─────────────────────────────────────────┘ │
│                                                                 │
│  [Сбросить к дефолтам жанра]                                   │
│                                                                 │
│  ℹ️ Действия «Прыжок», «Стрельба», «Действие» недоступны:     │
│     в этой игре нет соответствующих настроек/легенды.          │
└─────────────────────────────────────────────────────────────────┘
```

**Список действий** формируется сервером по §2.2. Для каждого — строка.

**Отображение клавиш** — человекочитаемо (см. §3.5).

### 3.2. Назначение клавиши

Клик ✎ → поле «Нажмите клавишу» → захват `KeyboardEvent.code`.

**Логика:**

1. Захват кода через `keydown` (только для назначения, не для игры).
2. Валидация:
   - Код соответствует regex §2.5.
   - Не модификатор-обёртка (как единственная клавиша).
   - Клавиша уже назначена на это действие → ошибка «Уже назначена».
   - Клавиша назначена на другое действие → **предупреждение**: «Клавиша X уже используется в действии Y. Назначить всё равно?». После подтверждения — назначается.
3. Если у действия уже 2 клавиши — ошибка «Максимум 2 клавиши на действие».
4. Escape — отмена.

### 3.3. Удаление клавиши

Кнопка ✕ удаляет клавишу из действия.

- Если это единственная клавиша **обязательного** действия — при сохранении ошибка «Действие X должно иметь минимум одну клавишу».
- Для **опциональных** действий удаление единственной клавиши допустимо (действие отключается).

### 3.4. Сброс к дефолтам

Кнопка «Сбросить к дефолтам жанра» открывает модалку:
«Текущие назначения будут заменены значениями жанра. Продолжить?»
После подтверждения — форма заполняется `genre.default_controls`. Не сохраняется до общей кнопки «Сохранить».

### 3.5. Отображение клавиш

Справочник:

| Code | Отображение |
|---|---|
| `ArrowLeft` | ← |
| `ArrowRight` | → |
| `ArrowUp` | ↑ |
| `ArrowDown` | ↓ |
| `Space` | Space |
| `Escape` | Esc |
| `Enter` | Enter |
| `Tab` | Tab |
| `KeyA`–`KeyZ` | A–Z |
| `Digit0`–`Digit9` | 0–9 |
| `ShiftLeft` / `ShiftRight` | L-Shift / R-Shift |
| `ControlLeft` / `ControlRight` | L-Ctrl / R-Ctrl |
| `AltLeft` / `AltRight` | L-Alt / R-Alt |
| `MetaLeft` / `MetaRight` | L-Meta / R-Meta |
| `F1`–`F12` | F1–F12 |
| `Numpad0`–`Numpad9` | Num0–Num9 |
| `Backquote` | ` |
| `Minus` | - |
| `Equal` | = |
| `BracketLeft` / `BracketRight` | [ / ] |
| `Backslash` | \ |
| `Semicolon` | ; |
| `Quote` | ' |
| `Comma` | , |
| `Period` | . |
| `Slash` | / |

### 3.6. Отключённые действия

Если действие в §2.2 неактуально — оно **не отображается** в списке, но остаётся в JSON. При повторной активации условия (например, добавлении `diggable` в легенду) — появляется снова с прежними назначениями.

**Если у игры нет `player_attacks`** (блок 10) — блок «Стрельба» и «Прыжок» (для `static-screen` без `jump_power`) скрыты. См. подсказку внизу формы.

---

## 4. UI: вкладка «Настройки»

### 4.1. Layout

```
┌─ Основное ─┬─ Легенда ─┬─ Уровни ─┬─ Управление ─┬─ Настройки ─┐
│            │           │          │              │  (активна)  │
└────────────┴───────────┴──────────┴──────────────┴─────────────┘
┌─ Общие ─────────────────────────────────────────────────────────┐
│  Скорость (speed_scale)        [1.1 ] (0.5–2.0)                 │
│  Мерцание спрайтов             [✓]                              │
│  Интенсивность мерцания        [0.12] (0.0–1.0)                 │
│  Отзывчивость управления       [0.90] (0.0–1.0)                 │
│  Громкость звука               [0.04] (0.0–0.3)                 │
│  Звук включён                  [✓]                              │
│  Частота кадров                (•) 60  ( ) 30                   │
│  Показывать HUD                [✓]                              │
│  Скорость анимаций             [1.0 ] (0.5–2.0)                 │
└─────────────────────────────────────────────────────────────────┘
┌─ Физика ▼ ──────────────────────────────────────────────────────┐
│  Гравитация                    [0.40] (0.0–2.0)                │
│  Скорость движения             [1.6 ] (0.5–10.0)               │
│  Сила прыжка                   [0.0 ] (-10.0–0.0, 0 = нет)     │
│  Скорость лазания              [1.2 ] (0.5–3.0)                │
│  Coyote time (мс)              [100 ] (0–300)                  │
│  Jump buffer (мс)              [150 ] (0–300)                  │
└─────────────────────────────────────────────────────────────────┘
┌─ Бой ▼ (без атак игрока — они в блоке 10) ─────────────────────┐
│  Неуязвимость после урона (мс) [1200] (0–5000)                │
│  Урон от касания моба          [1   ] (0–10)                   │
└─────────────────────────────────────────────────────────────────┘
┌─ Ямы (static-screen) ▼ ─────────────────────────────────────────┐
│  Время жизни ямы (мс)          [2500] (500–10000)              │
│  Время в яме (мс)              [2000] (500–5000)               │
└─────────────────────────────────────────────────────────────────┘
┌─ Атаки игрока ▼ (описано в блоке 10) ──────────────────────────┐
│  ... (поля редактируются здесь; схема — блок 10, §2.1)         │
└─────────────────────────────────────────────────────────────────┘
┌─ Боевые свойства игрока ▼ (описано в блоке 10) ────────────────┐
│  ... (lives, health_max, dodge, parry — блок 10, §2.3)         │
└─────────────────────────────────────────────────────────────────┘

[Сбросить к дефолтам жанра]
```

**Группы полей — сворачиваемые.** Специфичные группы отображаются только для соответствующего движка (`Ямы` — `static-screen` при `diggable`; `Камера` — `horizontal-scroll`; `Скролл` — `vertical-scroll`).

### 4.2. Группы полей

**«Общие»** — 9 полей (§2.7). Всегда видны.

**«Физика»** — поля по движку:

- `static-screen` (gravity): `gravity`, `move_speed`, `jump_power`, `climb_speed`, `swim_speed`, `coyote_time_ms`, `jump_buffer_ms`, `max_fall_speed`.
- `static-screen` (top_down): `move_speed`, `diagonal_movement`.
- `vertical-scroll`: `move_speed`, `vertical_move_speed`, `vertical_range`, `boundary_behavior`.
- `horizontal-scroll`: `gravity`, `move_speed`, `jump_power`, `max_fall_speed`, `coyote_time_ms`, `jump_buffer_ms`, `variable_jump`, `double_jump`, `air_control`.

**«Камера»** (только `horizontal-scroll`): `camera_mode`, `camera_lerp`.

**«Скролл»** (только `vertical-scroll`): `scroll_direction`, `scroll_speed`, `world_generation`.

**«Бой»** — `invulnerability_ms`, `enemy_contact_damage`, `player_melee_cooldown_ms` (если применимо), `player_fire_rate_ms` (если применимо).

**«Ямы»** (только `static-screen` при наличии `diggable`): `hole_duration_ms`, `trap_duration_ms`.

**«Топливо»** (только `vertical-scroll`): `fuel_capacity`, `fuel_consumption_per_sec`.

**«Атаки игрока»** и **«Боевые свойства игрока»** — отображаются на этой вкладке, но их схема и валидация описаны в блоке 10. На шаге 9 (без блока 10) эти блоки скрыты с пометкой «Доступно на шаге 10».

### 4.3. Визуальные индикаторы

- Значения вне диапазона — красная рамка, сообщение у поля.
- Значения, отличающиеся от `genre.engine_config`, — пометка «изменено» (синяя полоска слева).
- Кнопка «Сбросить поле к дефолту жанра» — иконка ↺ у каждого изменённого поля (в дополнение к общему сбросу).

### 4.4. Сброс к дефолтам

- **«Сбросить к дефолтам жанра»** — очищает `engine_overrides` (пустой объект). Используются значения `genre.engine_config`.
- **«Сбросить к дефолтам движка»** — записывает встроенные дефолты движка (`DEFAULT_ENGINE_CONFIG` в коде движка); на случай, если у жанра странные настройки.

Обе кнопки — модалки с подтверждением.

---

## 5. UI: блок «Звуки (бипер)»

### 5.1. Layout

Вкладка «Настройки» → свёрнутый блок **«Звуки (бипер)»**:

```
┌─ Звуки (бипер) ▼ ──────────────────────────────────────────────┐
│  ┌─ Событие ──┬─ Волна ──┬─ Частота ┬─ Конец ┬─ Длит ┬─ Гром ┬─ Огиб ┬─ ▶ ┐
│  │ jump       │ square   │ 880      │ —      │ 40    │ 0.03 │ decay│ [▶] │
│  │ pickup     │ square   │ 1320     │ —      │ 60    │ 0.04 │ pluck│ [▶] │
│  │ dig        │ square   │ 440      │ —      │ 50    │ 0.03 │ decay│ [▶] │
│  │ hit        │ square   │ 220      │ —      │ 80    │ 0.04 │ decay│ [▶] │
│  │ death      │ sawtooth │ 220      │ 55     │ 400   │ 0.06 │ lin  │ [▶] │
│  │ win        │ square   │ 1760     │ —      │ 250   │ 0.05 │ pluck│ [▶] │
│  │ [+ добавить]                                                          │
│  └────────────┴──────────┴──────────┴────────┴───────┴──────┴──────┴─────┘
│  Использовано: 6 из 10                                                 │
│  [Сбросить к дефолтам жанра]                                           │
└─────────────────────────────────────────────────────────────────────────┘
```

### 5.2. Столбцы

| Столбец | Поле | Редактируемое |
|---|---|---|
| Событие | `event` | Выбор из 10 (только незанятые) |
| Волна | `wave` | Селект: `square` / `triangle` / `sawtooth` / `noise` |
| Частота | `frequency_hz` | Числовое, 20–4000 |
| Конец | `frequency_end_hz` | Числовое 20–4000 или «—» |
| Длит | `duration_ms` | Числовое 10–1000 |
| Гром | `volume` | Числовое 0.0–0.3 |
| Огиб | `envelope` | Селект: `linear` / `decay` / `pluck` |
| ▶ | — | Кнопка прослушивания |

### 5.3. Добавление звука

Кнопка «+ добавить» → выбирается первое свободное событие из 10; строка с дефолтными значениями:

```json
{
  "event": "<первое свободное>",
  "wave": "square",
  "frequency_hz": 880,
  "frequency_end_hz": null,
  "duration_ms": 50,
  "volume": 0.03,
  "envelope": "decay"
}
```

При 10 занятых — кнопка disabled с подсказкой «Максимум 10 звуков».

### 5.4. Прослушивание

Кнопка ▶ → воспроизведение через `audio-core.js` с учётом `sound_volume` из настроек. Длительность и параметры — из строки.

**Реализация:**

- Если `AudioContext` не разблокирован (первое взаимодействие) — при клике ▶ разблокируется и проигрывается.
- `wave: "noise"` — через буфер шума.
- `envelope` — линейная / экспоненциальная / плак.

### 5.5. Удаление звука

Кнопка ✕ в строке → событие освобождается; удаляется из `sounds[]`.

### 5.6. Сброс

Кнопка «Сбросить к дефолтам жанра» — заменяет `game.sounds` на `genre.default_sounds` (модалка с подтверждением).

### 5.7. Событие без записи

Если в `sounds[]` нет события — движок не играет звук для этого события. Без ошибки.

---

## 6. Копирование настроек

В карточке игры, на вкладке «Настройки», кнопка **«Скопировать настройки из…»**:

```
┌─ Скопировать настройки ─────────────────────────────────┐
│  Источник: [Золото Бунгелингов ▼]                       │
│  (только игры того же движка)                           │
│                                                          │
│  Что копировать:                                        │
│  ☑ controls                                             │
│  ☑ sounds                                               │
│  ☑ engine_overrides                                     │
│                                                          │
│  [Копировать]                              [Отмена]     │
└──────────────────────────────────────────────────────────┘
```

**Логика:**

- **Источник** — другая игра **того же движка** (проверка `genre.engine === current.genre.engine`).
- После копирования — `audit.log` → `game.settings_copy` с указанием источника и полей.
- Доступно только в существующей игре (не при создании).
- Копирование **перезаписывает** выбранные поля без мержа.

**Копирование `player_attacks`, `player`, `scoring`** — если будут скопированы (когда блок 10 реализован), они тоже копируются в `engine_overrides` (как часть настроек). В UI — отдельный чекбокс «Боевые настройки» (после блока 10).

---

## 7. Валидация

### 7.1. `controls`

| Правило | Проверка |
|---|---|
| Все обязательные действия движка назначены | `required_actions ⊆ keys(controls)` |
| Каждое действие имеет 1–2 клавиши | `1 ≤ count ≤ 2` для обязательных; `0–2` для опциональных |
| Коды — валидные `KeyboardEvent.code` | Regex §2.5 |
| Никаких модификаторов-обёрток как единственной клавиши | Проверка |
| Нет более 2 клавиш на действие | `count ≤ 2` |

### 7.2. `sounds`

| Правило | Проверка |
|---|---|
| ≤ 10 записей | `count ≤ 10` |
| `event` ∈ 10 допустимых | Enum |
| `event` уникален | Проверка дубликатов |
| `wave` ∈ 4 | Enum |
| `frequency_hz` 20–4000 | Числовое |
| `frequency_end_hz` `null` или 20–4000 | Числовое |
| `duration_ms` 10–1000 | Числовое |
| `volume` 0.0–0.3 | Числовое |
| `envelope` ∈ 3 | Enum |

### 7.3. `engine_overrides`

| Правило | Проверка |
|---|---|
| Все значения в диапазонах §2.7 | По таблице |
| `fps` ∈ {30, 60} | Enum |
| `camera_mode` ∈ {follow, room, fixed} | Enum |
| `scroll_direction` ∈ {up, down} | Enum |
| `world_generation` ∈ {grid, procedural} | Enum |
| `movement_type` ∈ 4 | Enum |
| `view_mode` ∈ {side, top} | Enum |
| `speed_scale` 0.5–2.0 | Числовое |
| `sound_volume` 0.0–0.3 | Числовое |
| `physics.gravity ≥ 0` | Числовое |
| `physics.jump_power ≤ 0` | Числовое |
| Согласованность `movement_type` × `view_mode` | `top_down_*` → `view_mode: top`, `gravity` → `view_mode: side` |
| Согласованность `movement_type: top_down` × `gravity = 0` | Автокоррекция с предупреждением |

**При невалидном значении** — ошибка у поля; сохранение блокируется.

### 7.4. Согласованность

| Проверка | Действие |
|---|---|
| `ranged.enabled` (блок 10) → `controls.fire` назначен | Ошибка |
| `melee.enabled` (блок 10) → `controls.fire` назначен | Ошибка |
| `stomp.enabled` (блок 10) → только `gravity`/`horizontal-scroll` | Ошибка |
| `special.enabled` (блок 10) → `controls.action` назначен | Ошибка |
| `physics.jump_power < 0` → `controls.jump` назначен (для `horizontal-scroll`) | Ошибка |
| `diggable` в легенде → `controls.dig_left`/`dig_right` назначены (для `static-screen`) | Ошибка |

**Валидация реализуется на сервере** при сохранении; при ошибке — flash и подсветка соответствующего поля.

---

## 8. Безопасность

| Угроза | Мера |
|---|---|
| CSRF | Токен из блока 3 |
| XSS через `event`/`wave`/`envelope` | Enum-валидация; `htmlspecialchars()` при выводе |
| Подмена `KeyboardEvent.code` на произвольную строку | Regex §2.5 |
| Подмена значений `sound_volume` на > 0.3 | Серверная проверка диапазона |
| Подмена `frequency_hz` на > 4000 | Серверная проверка |
| DoS через бесконечный звук (`duration_ms`) | Максимум 1000 мс |
| Ввод в поля с числами | `floatval`, NaN/Infinity отклоняются |
| `fps` как произвольное число | Enum {30, 60} |
| Подмена `engine_overrides` при сохранении «Основного» | Сервер игнорирует `controls`/`sounds`/`engine_overrides` из формы вкладки «Основное»; редактируются только на «Управлении» и «Настройках» |
| Подмена `genre.engine_config` через `engine_overrides` | Сервер проверяет только поля из белого списка §2.7; неизвестные поля отбрасываются |

---

## 9. Аудит

```json
{"ts":"...","user":"admin","action":"game.controls_update","target":"game-001","details":{"changed_actions":["jump","fire"],"added_keys":[["jump","KeyZ"],["fire","Space"]]}}
{"ts":"...","user":"admin","action":"game.sounds_update","target":"game-001","details":{"changed_events":["pickup","death"]}}
{"ts":"...","user":"admin","action":"game.engine_overrides_update","target":"game-001","details":{"changed":["speed_scale","sprite_flicker","physics.gravity"]}}
{"ts":"...","user":"admin","action":"game.settings_reset","target":"game-001","details":{"reset":["engine_overrides"],"from":"genre"}}
{"ts":"...","user":"admin","action":"game.settings_copy","target":"game-001","details":{"source":"game-005","fields":["controls","sounds","engine_overrides"]}}
```

---

## 10. Критерии приёмки блока 9

### 10.1. Управление

1. **Вкладка «Управление» активна** для игры любого движка.
2. **Список действий соответствует движку.** `static-screen` с Lode Runner-легендой: `left`, `right`, `up`, `down`, `dig_left`, `dig_right`, `pause`, `start`. `horizontal-scroll`: `left`, `right`, `up`, `down`, `jump`, `fire`, `pause`, `start`.
3. **Назначение клавиши.** Клик ✎ → «Нажмите клавишу» → нажатие `KeyZ` → клавиша добавляется. До 2 клавиш на действие.
4. **Конфликты.** Назначение `KeyA` на `left` и затем на `right` → предупреждение «Уже используется в „Влево"», при подтверждении — назначается.
5. **Удаление клавиши.** ✕ убирает клавишу. Удаление единственной клавиши обязательного действия — ошибка при сохранении.
6. **Сброс к дефолтам.** Кнопка заменяет текущее на `genre.default_controls`.
7. **Неактивные действия.** Не появляются в списке; сохраняются в JSON.
8. **Отображение клавиш** — человекочитаемо (см. §3.5).
9. **Сохранение.** Изменения сохраняются в `game.controls`; применяются при следующем `/play/...`.

### 10.2. Настройки

10. **Вкладка «Настройки» активна.**
11. **Общие параметры.** Все 9 полей с диапазонами отображаются и редактируются.
12. **Специфичные параметры.**
    - `static-screen`: `physics`, `combat` (без player_attacks), `hole_duration_ms`, `trap_duration_ms`.
    - `vertical-scroll`: `scroll_speed`, `physics`, `combat`, `fuel_capacity`.
    - `horizontal-scroll`: `camera_mode`, `camera_lerp`, `physics`, `combat`.
13. **Валидация.** `speed_scale = 3.0` → ошибка «0.5–2.0». `fps = 45` → ошибка «Допустимо 30 или 60». `physics.gravity = -1` → ошибка.
14. **Сохранение.** Значения сохраняются в `game.engine_overrides`.
15. **Применение в игре.** `speed_scale = 0.5` → игрок и мобы двигаются вдвое медленнее. `sprite_flicker = true` → мерцание. `input_responsiveness = 0.3` → задержка управления.
16. **Сброс к дефолтам жанра.** Очищает `engine_overrides`.
17. **Сброс к дефолтам движка.** Записывает встроенные дефолты.
18. **Пометка «изменено».** Поля, отличающиеся от жанра, подсвечены; иконка ↺ сбрасывает одно поле.
19. **Согласованность `movement_type` × `view_mode`.** `top_down_8dir` + `view_mode: side` → ошибка. `gravity` + `view_mode: top` → ошибка.

### 10.3. Звуки

20. **Блок «Звуки».** Отображает текущий список (пустой или до 10 записей).
21. **Добавление звука.** Кнопка «+ добавить» → первое свободное событие; дефолтные значения.
22. **Выбор события.** Селект содержит только незанятые. При 10 занятых — кнопка disabled.
23. **Прослушивание.** ▶ у каждого звука — воспроизведение через Web Audio API с учётом `sound_volume`.
24. **Удаление.** ✕ убирает звук; событие освобождается.
25. **Валидация.** `frequency_hz = 5000` → ошибка «20–4000». `duration_ms = 0` → ошибка «10–1000». `volume = 0.5` → ошибка «0.0–0.3».
26. **Сохранение.** Звуки сохраняются в `game.sounds`; применяются в игре.
27. **Сброс к дефолтам жанра.** Заменяет `game.sounds`.
28. **Событие без записи** — движок не играет звук для этого события (без ошибки).
29. **`wave: noise`** — воспроизводится как шум (буфер).
30. **`envelope`** — `linear`, `decay`, `pluck` различаются на слух.

### 10.4. Согласованность

31. **`fire` не назначен при `player_attacks.ranged.enabled`** (блок 10) → ошибка сохранения.
32. **`jump` не назначен при `physics.jump_power < 0`** → ошибка сохранения (для `horizontal-scroll`).
33. **`dig_left`/`dig_right` не назначены при `diggable` в легенде** → ошибка сохранения (для `static-screen`).
34. **`action` не назначен при `player_attacks.special.enabled`** → ошибка.

### 10.5. Копирование настроек

35. **Кнопка «Скопировать настройки из…»** доступна в существующей игре.
36. **Выбор источника** — только игры того же движка; иначе ошибка «Источник должен быть того же движка».
37. **Копирование полей** — выбранные поля перезаписываются; `audit.log` → `game.settings_copy`.

### 10.6. Сохранение и атомарность

38. **Единая кнопка «Сохранить».** Сохраняет все пять вкладок атомарно.
39. **Ошибки по вкладкам.** При ошибке в «Настройках» — при сохранении подсвечивается вкладка «Настройки» и сообщение у поля.
40. **Обратная совместимость.** Если `game.controls`/`game.sounds`/`game.engine_overrides` не заданы (старые игры) — используются дефолты жанра.

### 10.7. Безопасность и аудит

41. **CSRF.** POST без токена — 403.
42. **XSS.** `event` с `<script>` — отклонён (enum-валидация).
43. **Аудит.** Все изменения записаны в `audit.log`.

### 10.8. Обратная совместимость

44. **Критерии блоков 1–8, 10** выполняются.

---

## 11. Оценка

**Ориентир:** 3–4 человеко-дня на разработчика (или ~1 рабочий день с ИИ-ассистентом).

Основное время:

- **Назначение клавиш** — перехват `keydown`, отображение кодов, конфликты, лимит 2 (≈0,7 дня).
- **Динамическая форма настроек** — группы полей по движку, диапазоны, специфичные параметры (≈0,7 дня).
- **Таблица звуков** — динамический список, прослушивание, лимит 10, дефолты (≈0,6 дня).
- **Валидация** — 25+ правил (значения, диапазоны, обязательные действия, согласованность) (≈0,5 дня).
- **Копирование настроек** — выбор источника, валидация движка (≈0,2 дня).
- **Сохранение** — атомарная запись (≈0,2 дня).
- **Применение в игре** — без изменений в движках (они уже читают эти поля с блоков 11) (≈0,1 дня).
- **Отладка и тестирование** (≈0,5 дня).

---

## 12. Что не делаем в блоке 9 и почему

- **Не реализуем `player_attacks`** — блок 10, §2.1. На шаге 9 блок «Атаки игрока» скрыт с пометкой «Доступно на шаге 10».
- **Не реализуем `player.lives`, `player.health_max`, `player.dodge`, `player.parry`** — блок 10, §2.3.
- **Не реализуем `scoring`** — блок 10, §2.6.
- **Не реализуем `player_attacks.special`** — блок 10, §2.1.
- **Не реализуем редактирование `genre.default_controls` / `default_sounds` / `engine_config`** — свойство жанра (блок 4, read-only). Если нужно — обновление блока 4 или блок 12 (опционально).
- **Не реализуем геймпад/тач** — только клавиатура.
- **Не реализуем пользовательские звуковые файлы** — только параметрические бипер-звуки.
- **Не реализуем приоритеты/микшер звуков** — упрощение: все звуки играют одновременно с ограничением громкости.
- **Не реализуем «горячую замену» настроек** во время игры — при перезапуске `/play/...` значения перечитываются.
- **Не реализуем «превью с движком»** прямо в админке на вкладке «Настройки» — админ проверяет через кнопку «Играть» (новая вкладка).
- **Не реализуем массовое копирование настроек** между играми — только по одной (кнопка «Скопировать настройки из…»); bulk — блок 12.
- **Не реализуем `KeyBinds` с модификаторами** (`Ctrl+S`) — конфликт с браузером.
- **Не реализуем глобальные настройки** (на уровне CMS) — только на уровне игры.

---

**Конец блока 9 из 13.**

Следующий блок — **Боевая система и триггеры** (`player_attacks`, `player.lives/health_max/dodge/parry`, `scoring`, боевые свойства мобов, триггеры, условия победы/поражения).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 10. Боевая система и триггеры

## 1. Общее описание

Две связанные подсистемы, обеспечивающие игровую динамику:

**Боевая система** — единая модель урона и реакций для всех трёх движков. Определяет:

- как игрок наносит урон (ближний бой, стрельба, стомп, спецприём);
- как мобы наносят урон игроку (касание, стрельба, взрыв);
- как урон применяется (HP, i-frames, knockback, stun);
- как мобы реагируют на попадания (смерть, морф, сплит, дроп);
- как считать очки и комбо.

**Триггеры** — событийная система «когда X → сделать Y», связывающая боевые события с остальной игрой (открыть дверь после убийства всех врагов, заспавнить босса при сборе предмета, показать сообщение при низком HP, изменить клетку, проиграть звук).

**Ключевой принцип:** обе подсистемы **data-driven**. Все параметры задаются через `engine_config` (жанр) и `engine_overrides` (игра), триггеры — через `level.params.triggers[]`. Движки реализуют механику, а не конкретную игру.

**Место в UI:**

- **Атаки игрока, боевые свойства игрока, очки** — на вкладке «Настройки» карточки игры (блок 9, §4).
- **Боевые свойства моба** — на вкладке «Легенда» карточки игры (блок 7, §7).
- **Свойства снаряда** — на вкладке «Легенда» (блок 7, §5.4).
- **Триггеры, условия победы/поражения** — на вкладке «Уровни» (блок 8, §6, §7).
- **«Тест поведения» / «Проверить триггеры»** — отладочные модалки, вызываемые из легенды и уровней соответственно.

---

## 2. Схема данных

### 2.1. `player_attacks` в `engine_config` / `engine_overrides`

```json
"player_attacks": {
  "melee": {
    "enabled": true,
    "damage": 1,
    "range_tiles": 1,
    "shape": "single",
    "cooldown_ms": 300,
    "hits": ["mob", "collider"],
    "knockback_tiles": 0,
    "stun_ms": 0
  },
  "ranged": {
    "enabled": true,
    "projectile_emoji": "➡️",
    "cooldown_ms": 200,
    "direction": "facing",
    "ammo": {"type": "infinite", "start": 10, "max": 30},
    "charge": {"enabled": false, "charge_ms": 800, "damage_multiplier": 2.0}
  },
  "stomp": {
    "enabled": true,
    "damage": 1,
    "stun_ms": 0,
    "morph_to": null,
    "bounce_power": -3.5
  },
  "special": {
    "enabled": false,
    "type": "screen_clear",
    "charges_max": 3,
    "recharge_by_pickup": "bomb",
    "action": "action",
    "freeze_duration_ms": 3000,
    "shield_duration_ms": 5000
  }
}
```

### 2.2. Поля `player_attacks`

#### `melee`

| Поле | Тип | Диапазон | Default | Описание |
|---|---|---|---|---|
| `enabled` | bool | — | false | Включён ли ближний бой |
| `damage` | number | 1–99 | 1 | Урон |
| `range_tiles` | number | 1–5 | 1 | Дальность удара |
| `shape` | enum | `single` \| `arc` \| `line` | `single` | Форма зоны удара |
| `cooldown_ms` | number | 50–2000 | 300 | Задержка между ударами |
| `hits` | array | подмножество `{mob, collider, obstacle}` | `["mob"]` | Какие цели получают урон |
| `knockback_tiles` | number | 0–5 | 0 | Отбрасывание |
| `stun_ms` | number | 0–2000 | 0 | Оглушение |

**Формы удара:**

- `single` — одна клетка в направлении взгляда.
- `arc` — три клетки: вперёд + две диагонали (для `top_down_8dir`) или вперёд + две боковые (для `gravity`).
- `line` — все клетки в направлении взгляда до `range_tiles`.

#### `ranged`

| Поле | Тип | Диапазон | Default | Описание |
|---|---|---|---|---|
| `enabled` | bool | — | false | Включена ли стрельба |
| `projectile_emoji` | string | emoji с ролью `projectile` | — | Снаряд; **обязателен при `enabled: true`** |
| `cooldown_ms` | number | 50–2000 | 200 | Задержка между выстрелами |
| `direction` | enum | `facing` \| `up` \| `down` | `facing` | Направление стрельбы |
| `ammo.type` | enum | `infinite` \| `count` | `infinite` | Тип боезапаса |
| `ammo.start` | number | 0–999 | 10 | Стартовое количество (при `count`) |
| `ammo.max` | number | 0–999 | 30 | Максимум (при `count`) |
| `charge.enabled` | bool | — | false | Заряжаемый выстрел |
| `charge.charge_ms` | number | 100–3000 | 800 | Время полной зарядки |
| `charge.damage_multiplier` | number | 1.0–10.0 | 2.0 | Множитель урона при полной зарядке |

**Требование:** если `ranged.enabled = true`, `projectile_emoji` должен существовать в эффективной легенде с ролью `projectile` и `damage ≥ 1`.

#### `stomp`

| Поле | Тип | Диапазон | Default | Описание |
|---|---|---|---|---|
| `enabled` | bool | — | false | Включён ли стомп |
| `damage` | number | 1–99 | 1 | Урон |
| `stun_ms` | number | 0–5000 | 0 | Оглушение (если моб не убит) |
| `morph_to` | string\|null | emoji моба или null | null | Превращение моба |
| `bounce_power` | number | -10.0–-1.0 | -3.5 | Отскок игрока |

**Применимость:** `stomp` доступен только в движках с гравитацией (`static-screen` с `movement_type: gravity`, `horizontal-scroll`). В `vertical-scroll` и `top_down` — игнорируется.

#### `special`

| Поле | Тип | Диапазон | Описание |
|---|---|---|---|
| `enabled` | bool | — | Включён ли спецприём |
| `type` | enum | `screen_clear` \| `time_stop` \| `shield` | Тип эффекта |
| `charges_max` | number | 1–9 | Заряды |
| `recharge_by_pickup` | string\|null | `pickup_id` | Чем пополнять |
| `action` | string | действие из `controls` | Кнопка активации |
| `freeze_duration_ms` | number | 100–10000 | Для `time_stop` |
| `shield_duration_ms` | number | 100–10000 | Для `shield` |

**Эффекты:**

- `screen_clear` — уничтожает всех мобов на экране (кроме боссов).
- `time_stop` — останавливает мобов на `freeze_duration_ms`.
- `shield` — даёт неуязвимость на `shield_duration_ms`.

### 2.3. `player` — расширение

В `engine_config.player` / `engine_overrides.player`:

```json
"player": {
  "lives": 3,
  "health_max": 5,
  "invulnerability_ms": 1000,
  "dodge": {
    "enabled": false,
    "duration_ms": 300,
    "invulnerability_ms": 300,
    "cooldown_ms": 800,
    "action": "fire"
  },
  "parry": {
    "enabled": false,
    "window_ms": 150,
    "cooldown_ms": 500,
    "action": "action"
  }
}
```

| Поле | Диапазон | Default |
|---|---|---|
| `lives` | 1–9 | 3 |
| `health_max` | 1–20 | 1 |
| `invulnerability_ms` | 0–5000 | 1000 |
| `dodge.duration_ms` | 100–1000 | 300 |
| `dodge.invulnerability_ms` | 0–1000 | 300 |
| `dodge.cooldown_ms` | 100–2000 | 800 |
| `parry.window_ms` | 50–500 | 150 |
| `parry.cooldown_ms` | 100–2000 | 500 |

**`dodge` и `parry`** — опциональны; `enabled: false` по умолчанию.

**Примечание:** поля `player.score_on_pickup`, `player.score_on_enemy_kill`, `player.fuel_capacity`, `player.fuel_consumption_per_sec` — из блока 9 (настройки движка); здесь не дублируются.

### 2.4. `mob` — расширение

В `legend[emoji]` для роли `mob` (помимо полей блока 7, §3.3):

```json
{
  "role": "mob",
  "hp": 3,
  "armor": 0,
  "knockback_resistance": 0.0,
  "stun_resistance": 0.0,
  "weak_point": null,
  "shield_front": null,
  "drop_emoji": null,
  "score_value": null,
  "explosion_on_death": null
}
```

#### Поля боевых свойств

| Поле | Тип | Диапазон | Default | Описание |
|---|---|---|---|---|
| `armor` | number | 0–10 | 0 | Снижает входящий урон: `damage = max(1, incoming − armor)` |
| `knockback_resistance` | number | 0.0–1.0 | 0.0 | Уменьшение knockback |
| `stun_resistance` | number | 0.0–1.0 | 0.0 | Уменьшение stun |
| `weak_point` | object\|null | — | null | Уязвимая точка |
| `shield_front` | object\|null | — | null | Фронтальный щит |
| `drop_emoji` | string\|null | emoji `pickup` | null | Дроп при смерти |
| `score_value` | number\|null | 0–100000 | null | Очки (переопределяет `scoring.on_enemy_kill`) |
| `explosion_on_death` | object\|null | — | null | Взрыв при смерти |

#### `weak_point`

```json
{
  "offset": {"x": 0, "y": -1},
  "radius_tiles": 1,
  "damage_multiplier": 2.0,
  "only_weak_point_vulnerable": false
}
```

| Поле | Диапазон |
|---|---|
| `offset.x`, `offset.y` | −3…3 |
| `radius_tiles` | 1–3 |
| `damage_multiplier` | 1.0–10.0 |
| `only_weak_point_vulnerable` | bool |

#### `shield_front`

```json
{
  "direction_cone_deg": 90,
  "damage_multiplier": 0.0
}
```

| Поле | Диапазон |
|---|---|
| `direction_cone_deg` | 30–180 |
| `damage_multiplier` | 0.0–1.0 |

#### `explosion_on_death`

```json
{
  "radius": 2,
  "damage": 2,
  "damages_player": true,
  "damages_mobs": false
}
```

| Поле | Диапазон |
|---|---|
| `radius` | 1–10 |
| `damage` | 1–99 |
| `damages_player` | bool |
| `damages_mobs` | bool |

### 2.5. `projectile` — расширение

В `legend[emoji]` для роли `projectile` (помимо полей блока 7, §3.3):

```json
{
  "role": "projectile",
  "speed": 4,
  "damage": 1,
  "lifetime_ms": 2000,
  "homing": false,
  "piercing": false,
  "bouncing": false,
  "bounces_max": 0,
  "returns": false,
  "pierces_walls": false,
  "friendly_fire": false
}
```

| Поле | Тип | Описание |
|---|---|---|
| `piercing` | bool | Прошивает врагов (не исчезает при попадании) |
| `bouncing` | bool | Отскакивает от стен |
| `bounces_max` | number 0–10 | Максимум отскоков |
| `returns` | bool | Бумеранг — возвращается к источнику |
| `pierces_walls` | bool | Проходит сквозь `solid`-тайлы |
| `friendly_fire` | bool | Наносит урон мобам-союзникам |

### 2.6. `scoring` в `engine_config` / `engine_overrides`

```json
"scoring": {
  "on_enemy_kill": 100,
  "on_pickup": 10,
  "combo": {
    "enabled": false,
    "window_ms": 2000,
    "multiplier_per_hit": 0.5,
    "max_multiplier": 5.0
  },
  "time_bonus": {
    "enabled": false,
    "base": 500,
    "per_second_remaining": 10
  }
}
```

| Поле | Диапазон | Default |
|---|---|---|
| `on_enemy_kill` | 0–100000 | 100 |
| `on_pickup` | 0–1000 | 10 |
| `combo.enabled` | bool | false |
| `combo.window_ms` | 500–10000 | 2000 |
| `combo.multiplier_per_hit` | 0.1–2.0 | 0.5 |
| `combo.max_multiplier` | 1.0–20.0 | 5.0 |
| `time_bonus.enabled` | bool | false |
| `time_bonus.base` | 0–10000 | 500 |
| `time_bonus.per_second_remaining` | 0–1000 | 10 |

- **`combo`** — каждое убийство в течение `window_ms` после предыдущего увеличивает множитель на `multiplier_per_hit`; множитель умножает очки; максимум — `max_multiplier`.
- **`time_bonus`** — при победе начисляется `base + remaining_seconds × per_second_remaining`.

**Примечание:** `player.score_on_pickup` и `player.score_on_enemy_kill` из блока 9 — старые поля. В схеме блока 10 они **заменяются** на `scoring.on_pickup` и `scoring.on_enemy_kill`. При миграции: если в существующем JSON есть `player.score_on_*`, значения переносятся в `scoring.on_*`, старые поля удаляются.

### 2.7. Триггеры — `level.params.triggers[]`

```json
{
  "id": "trig-001",
  "name": "Убить всех роботов",
  "enabled": true,
  "once": true,
  "when": {
    "type": "kill_count",
    "target_emoji": "🤖",
    "count": 5,
    "compare": "gte"
  },
  "then": [
    {"type": "open_exit", "level_id": "level-001"},
    {"type": "show_message", "text": "Выход открыт!", "duration_ms": 2000}
  ]
}
```

| Поле | Правило |
|---|---|
| `id` | `^trig-\d{3,}$`, уникален в уровне |
| `name` | 2–80 |
| `enabled` | bool |
| `once` | bool; при `true` срабатывает один раз |
| `when` | объект условия (см. §2.8) |
| `then` | массив действий 1–10 (см. §2.9) |
| **Лимиты** | `triggers.length ≤ 50`; `then.length ≤ 10`; `all_of/any_of` — до 5 вложенных, глубина ≤ 2 |

### 2.8. Условия `when`

| Тип | Параметры | Смысл |
|---|---|---|
| `kill_count` | `target_emoji`, `count`, `compare` (`gte`/`gt`/`eq`/`lt`/`lte`) | Убито N мобов emoji |
| `kill_all` | `target_emoji` (опц.) | Убиты все мобы (или все emoji) |
| `pickup_count` | `pickup_id`, `count`, `compare` | Собрано N предметов |
| `pickup_all` | `pickup_id` | Собраны все предметы типа |
| `player_hp` | `value`, `compare` | HP игрока относительно value |
| `player_lives` | `value`, `compare` | Жизни относительно value |
| `score` | `value`, `compare` | Очки относительно value |
| `time_elapsed` | `seconds` | Прошло N секунд |
| `player_in_zone` | `col`, `row`, `width`, `height` | Игрок в прямоугольной зоне |
| `player_touch_emoji` | `emoji` | Игрок коснулся клетки emoji |
| `flag_set` | `flag_name` | Флаг установлен |
| `all_of` | `triggers[]` (до 5) | Все вложенные условия истинны |
| `any_of` | `triggers[]` (до 5) | Хотя бы одно вложенное |

**`compare`:** `gte` \| `gt` \| `eq` \| `lt` \| `lte`; default `gte`.

**Валидация:**

- `target_emoji` существует с ролью `mob`.
- `pickup_id` существует.
- `col`/`row` в пределах сетки; `width`/`height` ≥ 1; зона не выходит за границы.
- `emoji` существует.
- `flag_name` — `^[a-z][a-z0-9_]{1,30}$`.

### 2.9. Действия `then[]`

| Тип | Параметры | Смысл |
|---|---|---|
| `open_exit` | `level_id` (опц.) | Открыть выход |
| `close_exit` | `level_id` (опц.) | Закрыть выход |
| `spawn` | `emoji`, `col`, `row`, `count` (опц.) | Заспавнить сущность |
| `despawn` | `emoji` | Удалить все сущности emoji |
| `set_tile` | `col`, `row`, `emoji` | Заменить клетку |
| `show_message` | `text` (1–200), `duration_ms` (500–10000) | Показать сообщение |
| `play_sound` | `event` (одно из 10) | Проиграть звук |
| `set_flag` | `flag_name` | Установить флаг |
| `clear_flag` | `flag_name` | Снять флаг |
| `change_player_stat` | `stat` (`hp`\|`score`\|`lives`), `delta` (−99…99) | Изменить характеристику |
| `teleport_player` | `col`, `row` | Телепортировать игрока |
| `damage_player` | `amount` (1–99) | Нанести урон |
| `damage_all_mobs` | `emoji` (опц.), `amount` (1–99) | Урон всем мобам |
| `victory` | — | Немедленная победа |
| `defeat` | — | Немедленное поражение |

**Валидация:**

- `spawn.emoji`/`despawn.emoji`/`set_tile.emoji` — существуют в эффективной легенде.
- `spawn.col`/`row` в границах.
- `play_sound.event` ∈ 10 событий (блок 9, §2.4).
- `text` — plain text, экранируется при выводе.
- `flag_name` — `^[a-z][a-z0-9_]{1,30}$`.
- `change_player_stat.stat` ∈ `{hp, score, lives}`.
- `delta` — целое −99…99.

### 2.10. Условия победы — `level.params.victory_conditions[]`

```json
"victory_conditions": [
  {"type": "reach_exit"},
  {"type": "kill_all", "target_emoji": "🐉"}
]
```

| Тип | Параметры | Смысл |
|---|---|---|
| `reach_exit` | — | Достичь `exit` (с учётом `requires`/`mode`) |
| `kill_all` | `target_emoji` (опц.) | Убить всех мобов emoji |
| `collect_all` | `pickup_id` | Собрать все предметы типа |
| `score_reached` | `value` (0–100000) | Достичь очков |
| `time_under` | `seconds` (1–3600) | Пройти за N секунд |
| `flag_set` | `flag_name` | Флаг установлен |

**Правила:**

- Если `victory_conditions` не заданы — победа при достижении `exit` (совместимо с блоком 8).
- Если заданы — победа только при выполнении **всех** условий.
- Проверяются на каждом кадре.

### 2.11. Условия поражения — `level.params.defeat_conditions[]`

```json
"defeat_conditions": [
  {"type": "hp_zero"},
  {"type": "lives_zero"},
  {"type": "time_limit_exceeded"}
]
```

| Тип | Смысл |
|---|---|
| `hp_zero` | HP игрока ≤ 0 (зависит от `lives`) |
| `lives_zero` | Жизни = 0 |
| `time_limit_exceeded` | Таймер уровня истёк |
| `player_fell` | Игрок упал за пределы уровня |
| `flag_set` | Флаг установлен |

**Правила:**

- Если `defeat_conditions` не заданы — стандартные правила движка (упал в пропасть, HP = 0 при `lives = 0`).
- Если заданы — поражение при выполнении **любого** условия.

**Приоритет:** если в одном кадре выполнены и `victory_conditions`, и `defeat_conditions` — **победа** приоритетнее.

---

## 3. Модель урона

### 3.1. Формула

```
final_damage = max(1, (source.damage × weak_point_multiplier × shield_multiplier) − target.armor)
```

Где:

- `source.damage` — урон источника (снаряд, melee, `damage_on_touch`, взрыв).
- `weak_point_multiplier` — 1.0, если цель не имеет `weak_point` или попадание вне зоны; иначе `weak_point.damage_multiplier`.
- `shield_multiplier` — 1.0, если цель не имеет `shield_front` или попадание вне конуса; иначе `shield_front.damage_multiplier`.
- `target.armor` — `mob.armor` или `player.armor` (у игрока пока 0).
- `max(1, ...)` — минимальный урон 1.

**Исключения:**

- `shield_front.damage_multiplier = 0.0` → урон 0 (полная блокировка).
- `weak_point.only_weak_point_vulnerable = true` и попадание вне зоны → урон 0.

### 3.2. Применение к игроку

```
1. Если now < player.invulnerable_until — урон игнорируется.
2. player.hp -= final_damage.
3. player.invulnerable_until = now + player.invulnerability_ms.
4. playSound('hit').
5. Если player.hp <= 0:
   а. player.lives -= 1.
   б. Если player.lives <= 0: triggerDefeat('lives_zero').
   в. Иначе: respawnPlayer() на start или последнем чекпоинте (horizontal-scroll).
```

### 3.3. Применение к мобу

```
1. Если mob.shield_front и попадание в конус — damage × shield_multiplier.
2. Если mob.weak_point:
   а. in_zone = попадание в зону weak_point.
   б. Если in_zone: damage × weak_point.damage_multiplier.
   в. Иначе если only_weak_point_vulnerable: return (урон игнорируется).
3. damage = max(1, damage - mob.armor).
4. mob.hp -= damage.
5. mob.stunned_until = now + (source.stun_ms × (1 - mob.stun_resistance)).
6. mob.knockback_velocity = source.knockback × (1 - mob.knockback_resistance).
7. playSound('hit').
8. Если mob.hp <= 0: killMob(mob, source).
```

### 3.4. Смерть моба

```
1. playSound('death').
2. score += (mob.score_value ?? scoring.on_enemy_kill) × combo_multiplier.
3. Если mob.drop_emoji: spawnPickup(mob.drop_emoji, mob.x, mob.y).
4. Если mob.explosion_on_death:
   applyAreaDamage(mob.x, mob.y, radius, damage, {player, mobs}).
5. markMobDead(mob).
6. checkTriggers('kill_count', mob.emoji).
```

### 3.5. Порядок разрешения урона за кадр

1. Столкновение игрока с мобами → `damage_on_touch`.
2. Столкновение игрока с harmful-тайлами → `tile.damage_on_touch`.
3. Столкновение снарядов с игроком/мобами/стенами.
4. Столкновение снарядов с другими снарядами.
5. `stomp`: игрок падает (`vy > 0`) и пересекается с мобом сверху → `on_stomp`.
6. Триггеры.

**Приоритет источников** — по порядку. Если игрок умер на шаге 1, шаги 3–6 для него не выполняются (кроме `respawn`).

### 3.6. Friendly fire

- Снаряд моба наносит урон **только** игроку (по умолчанию).
- Если `projectile.friendly_fire = true` — наносит урон и другим мобам.
- Снаряд игрока наносит урон мобам и `collider`-объектам, но не `obstacle`-тайлам (кроме `pierces_walls: true`).

### 3.7. Влияние `speed_scale`

`speed_scale` (блок 9) **не влияет** на урон, но влияет на скорость снарядов и частоту атак мобов (через `rate_ms` у мобов не масштабируется — частота фиксирована). `cooldown_ms` у игрока **не масштабируется**.

---

## 4. Триггеры

### 4.1. Схема

См. §2.7.

### 4.2. Порядок выполнения

Триггеры проверяются **каждый кадр** после разрешения урона и столкновений. Для каждого триггера:

1. Проверка `enabled`.
2. Проверка `once`: если уже срабатывал — пропуск.
3. Проверка `when`: если ложно — пропуск.
4. Выполнение `then[]` **последовательно**. При ошибке в действии (например, `spawn` в занятую клетку) — действие пропускается, остальные выполняются; `audit.log` → `trigger.action_failed`.
5. Если `once: true` — пометка о выполнении.

### 4.3. Отладка

См. §6.5.

### 4.4. Валидация триггеров

| Правило | Действие при провале |
|---|---|
| `id` уникален, `^trig-\d{3,}$` | Ошибка |
| `when.type` ∈ 13 типов | Ошибка |
| `then[].type` ∈ 15 типов | Ошибка |
| `target_emoji` — emoji с ролью `mob` | Ошибка |
| `pickup_id` — `pickup_id` из легенды | Ошибка |
| `spawn.emoji`/`despawn.emoji`/`set_tile.emoji` — в легенде | Ошибка |
| `play_sound.event` ∈ 10 событий | Ошибка |
| `flag_name` — `^[a-z][a-z0-9_]{1,30}$` | Ошибка |
| `change_player_stat.stat` ∈ `{hp, score, lives}` | Ошибка |
| `delta` — целое −99…99 | Ошибка |
| `spawn.col`/`row` в границах сетки | Ошибка |
| `triggers.length ≤ 50` | Ошибка |
| `then.length ≤ 10` | Ошибка |
| `all_of`/`any_of` — ≤ 5 вложенных, глубина ≤ 2 | Ошибка |

---

## 5. Условия победы и поражения

Схемы — §2.10, §2.11.

**Порядок проверки за кадр:**

1. Проверить `victory_conditions` — если **все** выполнены → `victory`.
2. Проверить `defeat_conditions` — если **хотя бы одно** выполнено → `defeat`.
3. Если в одном кадре — победа приоритетнее.

**`time_bonus`:** при победе — `score += base + remaining_seconds × per_second_remaining` (если `enabled`).

---

## 6. UI: интеграция с админкой

### 6.1. Вкладка «Настройки» — блок «Атаки игрока»

```
┌─ Атаки игрока ▼ ────────────────────────────────────────────────┐
│                                                                  │
│ ── Ближний бой ──                                               │
│  ☑ Включён                                                     │
│  Урон         [1]    (1–99)                                    │
│  Дальность    [1]    (1–5)                                     │
│  Форма        [single ▼]  single / arc / line                  │
│  Cooldown     [300]  мс (50–2000)                              │
│  Цели         [✓ mob] [☐ collider] [☐ obstacle]                │
│  Отбрасывание [0]    (0–5)                                     │
│  Оглушение    [0]    мс (0–2000)                              │
│                                                                  │
│ ── Дальний бой ──                                               │
│  ☑ Включён                                                     │
│  Снаряд       [➡️ ▼]  (только emoji с ролью projectile)         │
│  Cooldown     [200]  мс (50–2000)                              │
│  Направление  [facing ▼]                                       │
│  Боезапас     (•) бесконечный  ( ) ограниченный                │
│   старт [10] / максимум [30]                                   │
│  Заряжаемый   ☐  время [800] мс / множитель [2.0]              │
│                                                                  │
│ ── Стомп (только gravity) ──                                    │
│  ☑ Включён                                                     │
│  Урон         [1]                                              │
│  Оглушение    [0]    мс                                        │
│  Превращение  [— ▼]  (mob emoji или «—»)                       │
│  Отскок       [-3.5] (-10.0 – -1.0)                            │
│                                                                  │
│ ── Спецприём ──                                                 │
│  ☐ Включён                                                     │
│  Тип          [screen_clear ▼]                                 │
│  Заряды       [3]    (1–9)                                     │
│  Пополнение   [bomb ▼]  (pickup_id или «—»)                    │
│  Кнопка       [action ▼]                                       │
│  (для time_stop) freeze [3000] мс                              │
│  (для shield)    shield [5000] мс                              │
│                                                                  │
│  [Сбросить к дефолтам жанра]                                   │
└──────────────────────────────────────────────────────────────────┘
```

**Валидация (на сервере при сохранении):**

- `melee.enabled` → `controls.fire` назначен.
- `ranged.enabled` → `projectile_emoji` существует с ролью `projectile`.
- `ranged.ammo.type: count` → `start ≤ max`.
- `stomp.enabled` → доступно только для `gravity`/`horizontal-scroll`.
- `special.enabled` → `action` назначен; `recharge_by_pickup` существует с ролью `pickup`.
- `special.type: time_stop` → `freeze_duration_ms` задан.
- `special.type: shield` → `shield_duration_ms` задан.

### 6.2. Вкладка «Настройки» — блок «Игрок»

```
┌─ Игрок ▼ ───────────────────────────────────────────────────────┐
│  Жизни         [3   ]  (1–9)                                   │
│  Здоровье      [5   ]  (1–20)                                  │
│  Неуязвимость  [1000]  мс (0–5000)                             │
│                                                                  │
│ ── Уклонение ──                                                 │
│  ☐ Включено                                                    │
│  Длительность     [300]  мс                                    │
│  Неуязвимость     [300]  мс                                    │
│  Cooldown         [800]  мс                                    │
│  Кнопка           [fire ▼]                                     │
│                                                                  │
│ ── Парирование ──                                               │
│  ☐ Включено                                                    │
│  Окно             [150]  мс                                    │
│  Cooldown         [500]  мс                                    │
│  Кнопка           [action ▼]                                   │
└──────────────────────────────────────────────────────────────────┘
```

### 6.3. Вкладка «Настройки» — блок «Очки»

```
┌─ Очки ▼ ────────────────────────────────────────────────────────┐
│  За убийство моба  [100]                                       │
│  За предмет        [10 ]                                       │
│                                                                  │
│ ── Комбо ──                                                     │
│  ☐ Включено                                                    │
│  Окно             [2000] мс                                    │
│  Множитель за удар [0.5]                                       │
│  Максимум         [5.0]                                        │
│                                                                  │
│ ── Бонус за время ──                                            │
│  ☐ Включено                                                    │
│  База             [500]                                        │
│  За секунду       [10 ]                                        │
└──────────────────────────────────────────────────────────────────┘
```

### 6.4. Вкладка «Легенда» — блок «Боевые свойства» моба

См. блок 7, §7. Поля соответствуют §2.4.

### 6.5. Вкладка «Легенда» — блок «Свойства снаряда»

См. блок 7, §5.4. Поля соответствуют §2.5.

### 6.6. Вкладка «Уровни» — вкладка «Триггеры»

См. блок 8, §6.

**Форма триггера:**

```
┌─ Триггер trig-001 ──────────────────────────────────────────────┐
│  Название    [Убить всех роботов________________]              │
│  Включён     ☑                                                  │
│  Один раз    ☑                                                  │
│                                                                  │
│ ── Условие (КОГДА) ──                                           │
│  Тип        [kill_count ▼]                                     │
│  Emoji      [🤖 ▼]                                             │
│  Сравнение  [gte ▼]  Значение [5]                              │
│                                                                  │
│ ── Действия (ТОГДА) ──                                          │
│  ≡ [open_exit ▼]  level-001                            [🗑]    │
│  ≡ [show_message ▼]  "Выход открыт!"  [2000] мс       [🗑]    │
│  [+ Добавить действие]                                          │
│                                                                  │
│  [Сохранить]      [Отмена]                                      │
└──────────────────────────────────────────────────────────────────┘
```

**Форма `when` динамическая** — поля меняются по типу условия. Для `all_of`/`any_of` — вложенные условия (до 5).

**Форма `then[]`** — динамический список; каждое действие — карточка с полями по типу.

### 6.7. Вкладка «Уровни» — условия победы/поражения

См. блок 8, §7.

### 6.8. «Тест поведения» (из легенды моба)

См. блок 7, §6 — кнопка «▶ Тест поведения» в блоке «Поведение».

**Модалка:**

```
┌─ Тест поведения: Солдат шерифа (2×2) ──────────────────────────┐
│                                                                  │
│  Уровень: [Уровень 1 (Вход в шахту) ▼]   [✕ Закрыть]           │
│                                                                  │
│  ┌──────────────────────────────────────────────┐               │
│  │                                              │               │
│  │   [canvas 32×24 сетка]                       │               │
│  │                                              │               │
│  │   🧱 🧱 🧱 🧱 🧱 🧱 🧱 🧱                  │               │
│  │   🧱 🟩 🏃 🟩 🦹 🟩 🟩 🧱                  │               │
│  │   🧱 🧱 🚪 🧱 🧱 🧱 🧱 🧱                  │               │
│  │                                              │               │
│  │   [Игрок: 🏃] [Моб: 🦹 (2×2)]               │               │
│  │   Bounding box выделен пунктиром             │               │
│  └──────────────────────────────────────────────┘               │
│                                                                  │
│  ── Управление ──                                               │
│  Игрок: ← → ↑ ↓ (стрелки)                                      │
│                                                                  │
│  ── Отладка ──                                                  │
│  [✓] Показывать путь моба (линия)                              │
│  [✓] Показывать радиус видимости                              │
│  [✓] Показывать лог решений                                    │
│                                                                  │
│  Лог:                                                           │
│  > t=0.0: видит игрока (расстояние 3)                          │
│  > t=0.5: движется к игроку (greedy)                           │
│  > t=1.2: игрок вне радиуса → патруль                          │
│                                                                  │
│  [↻ Перезапустить]                        [Сохранить и играть] │
└──────────────────────────────────────────────────────────────────┘
```

**Логика:**

1. Копия уровня с одним экземпляром моба; остальные мобы/предметы удалены.
2. Движок жанра в режиме предпросмотра (без звуков, очков, game over).
3. Игрок движется стрелками, не умирает.
4. Отладочная визуализация.
5. «Сохранить и играть» — сохранение + `/play/{slug}/{level_id}` в новой вкладке.

### 6.9. «Проверить триггеры» (из уровня)

Аналогично — предпросмотр уровня с отладочной визуализацией триггеров:

```
┌─ Проверить триггеры: Уровень 1 ────────────────────────────────┐
│                                                                  │
│  ┌──────────────────────────────────────────────┐               │
│  │   [canvas 32×24 сетка]                       │               │
│  └──────────────────────────────────────────────┘               │
│                                                                  │
│  Состояние триггеров:                                          │
│  ○ trig-001  Убить всех роботов    (не выполнено)              │
│  ○ trig-002  Таймер 60 сек         (не выполнено)              │
│  ✓ trig-003  Найти секрет          (выполнено)                 │
│                                                                  │
│  Лог:                                                           │
│  > t=12.5s trig-002 сработал: damage_player(1)                 │
│  > t=15.0s trig-003 сработал: set_flag(found_secret)           │
│                                                                  │
│  [↻ Перезапустить]  [⏸ Пауза]  [✕ Закрыть]                    │
└──────────────────────────────────────────────────────────────────┘
```

---

## 7. Валидация (сводно)

| Проверка | Где | Действие |
|---|---|---|
| `melee.enabled` → `controls.fire` назначен | Game save | Ошибка |
| `ranged.enabled` → `projectile_emoji` существует с ролью `projectile` | Game save | Ошибка |
| `ranged.ammo.start ≤ max` | Game save | Ошибка |
| `stomp.enabled` → только `gravity`/`horizontal-scroll` | Game save | Ошибка |
| `special.enabled` → `action` назначен; `recharge_by_pickup` существует | Game save | Ошибка |
| `mob.weak_point.offset` ∈ [−3, 3] | Legend save | Ошибка |
| `mob.shield_front.direction_cone_deg` 30–180 | Legend save | Ошибка |
| `triggers[].id` уникален | Level save | Ошибка |
| `triggers[].when.type` ∈ 13 типов | Level save | Ошибка |
| `triggers[].then[].type` ∈ 15 типов | Level save | Ошибка |
| `triggers[].then[].spawn.emoji` существует | Level save | Ошибка |
| `triggers[].then[].play_sound.event` ∈ 10 | Level save | Ошибка |
| `triggers.length ≤ 50` | Level save | Ошибка |
| `then.length ≤ 10` | Level save | Ошибка |
| `all_of/any_of` — ≤ 5 вложенных, глубина ≤ 2 | Level save | Ошибка |
| `victory_conditions` валидны | Level save | Ошибка |
| `defeat_conditions` валидны | Level save | Ошибка |
| `scoring.combo.multiplier_per_hit ≤ max_multiplier` | Game save | Ошибка |

---

## 8. Безопасность

| Угроза | Мера |
|---|---|
| XSS через `triggers[].then[].show_message.text` | `htmlspecialchars()` при выводе в overlay |
| Инъекция через `when.type` / `then.type` | Enum-валидация |
| DoS через огромные массивы | Лимиты: 50 триггеров, 10 действий, 5 вложенных |
| Рекурсия `all_of`/`any_of` | Глубина ≤ 2 |
| Подмена `spawn.emoji` на несуществующий | Серверная проверка |
| Выполнение произвольного кода | Нет `eval`; только доверенные обработчики |
| CSRF | Токен из блока 3 |
| Подмена `player_attacks` при сохранении «Основного» | Сервер игнорирует `player_attacks` из формы вкладки «Основное»; редактируется только на «Настройках» |
| Подмена `triggers` при сохранении легенды | Сервер игнорирует `triggers` из формы вкладки «Легенда»; редактируются только на вкладке «Уровни» |

---

## 9. Аудит

```json
{"ts":"...","user":"admin","action":"game.player_attacks_update","target":"game-001","details":{"changed":["melee","ranged"]}}
{"ts":"...","user":"admin","action":"game.player_update","target":"game-001","details":{"changed":["lives","health_max"]}}
{"ts":"...","user":"admin","action":"game.scoring_update","target":"game-001","details":{"changed":["on_enemy_kill","combo.enabled"]}}
{"ts":"...","user":"admin","action":"legend.mob_combat_update","target":"game-001","details":{"emoji":"🦹","changed":["armor","weak_point"]}}
{"ts":"...","user":"admin","action":"legend.projectile_update","target":"game-001","details":{"emoji":"➡️","changed":["piercing"]}}
{"ts":"...","user":"admin","action":"level.trigger_create","target":"game-001","details":{"level_id":"level-001","trigger_id":"trig-001","when_type":"kill_count","then_types":["open_exit","show_message"]}}
{"ts":"...","user":"admin","action":"level.trigger_update","target":"game-001","details":{"level_id":"level-001","trigger_id":"trig-001","changed":["when.count"]}}
{"ts":"...","user":"admin","action":"level.trigger_delete","target":"game-001","details":{"level_id":"level-001","trigger_id":"trig-003"}}
{"ts":"...","user":"admin","action":"level.victory_conditions_update","target":"game-001","details":{"level_id":"level-001","conditions":["reach_exit","kill_all:🐉"]}}
{"ts":"...","user":"admin","action":"level.defeat_conditions_update","target":"game-001","details":{"level_id":"level-001","conditions":["hp_zero","time_limit_exceeded"]}}
```

---

## 10. Критерии приёмки блока 10

### 10.1. Атака игрока

1. **Ближний бой.** `melee.enabled: true`, `damage: 1`; нажатие `fire` наносит урон мобу в клетке впереди; моб с `hp: 1` умирает; очки начисляются.
2. **Форма `arc`.** Удар задевает три клетки; мобы в боковых клетках получают урон.
3. **Форма `line`.** Удар проходит сквозь N клеток; все мобы на линии получают урон.
4. **Стрельба.** `ranged.enabled: true`, `projectile_emoji: "➡️"`; `fire` спавнит снаряд; при попадании в моба — `damage` применяется.
5. **Ограниченный боезапас.** `ammo.type: count`, `start: 5`; после 5 выстрелов стрельба не работает; подбор `pickup` с `effect: ammo` пополняет.
6. **Заряжаемый выстрел.** `charge.enabled: true`, `charge_ms: 800`; удержание `fire` 800 мс → выстрел с `damage × multiplier`.
7. **Стомп.** В `gravity`-режиме прыжок на моба → моб получает `damage`; игрок отскакивает; при `morph_to: "🐢"` — моб превращается.
8. **Спецприём.** `special.enabled: true`, `type: screen_clear`; `action` уничтожает всех мобов на экране; заряды уменьшаются.

### 10.2. Модель урона

9. **HP-пул моба.** `mob.hp: 3`; три попадания → моб умирает.
10. **Броня.** `mob.armor: 2`; урон снаряда 3 → моб получает 1.
11. **Уязвимая точка.** `weak_point` задан; урон вне зоны игнорируется (при `only_weak_point_vulnerable: true`); в зоне × `damage_multiplier`.
12. **Фронтальный щит.** `shield_front` задан; урон с фронта блокируется (0.0), сзади — полный.
13. **Смерть моба.** `hp <= 0` → моб исчезает; `scoring.on_enemy_kill` начисляется; `drop_emoji` спавнится; `explosion_on_death` наносит урон в радиусе.
14. **Смерть игрока.** Урон > HP → `lives--`; респавн на чекпоинте; `invulnerability_ms` защищает.
15. **Game over.** `lives: 0` → game over.
16. **Уклонение.** `player.dodge.enabled: true`; `fire` → уклонение с неуязвимостью на 300 мс.
17. **Парирование.** `player.parry.enabled: true`; `action` в течение 150 мс → блокировка урона.

### 10.3. Реакции

18. **Knockback.** `melee.knockback_tiles: 2` → моб отбрасывается на 2 клетки.
19. **Stun.** `melee.stun_ms: 1000` → моб не двигается 1 секунду.
20. **Сопротивление.** `mob.knockback_resistance: 0.5` → отбрасывание уменьшается вдвое.
21. **Морф.** `on_stomp.morph_to: "🐢"` → превращение.
22. **Сплит.** AI `splitter`; при смерти моб делится.
23. **Piercing.** `projectile.piercing: true` → проходит сквозь мобов.
24. **Bouncing.** `projectile.bouncing: true` → отскакивает от стен.
25. **Returns.** `projectile.returns: true` → бумеранг.
26. **Pierces walls.** `projectile.pierces_walls: true` → проходит сквозь `solid`.
27. **Friendly fire.** `projectile.friendly_fire: true` → ранит других мобов.

### 10.4. Очки и комбо

28. **Очки.** Убийство моба → `score += 100`.
29. **Combo.** `combo.enabled: true`, `window_ms: 2000`; два убийства подряд → второй ×1.5; третье в окне → ×2.0.
30. **Time bonus.** При победе → `score += base + remaining × per_second`.
31. **Score_value.** `mob.score_value: 500` → за убийство начисляется 500.

### 10.5. Триггеры

32. **`kill_count`.** Убито 5 мобов `🤖` → `open_exit`; выход открывается.
33. **`pickup_all`.** Собраны все `💰` → `spawn` босса.
34. **`time_elapsed`.** Прошло 60 секунд → `damage_player` 1.
35. **`player_in_zone`.** Игрок в зоне → `play_sound` `alarm`.
36. **`all_of`.** Два условия истинны одновременно → действие.
37. **`any_of`.** Одно из двух → действие.
38. **`once: true`.** Триггер срабатывает один раз; при повторном событии не срабатывает.
39. **`enabled: false`.** Триггер не проверяется.
40. **`set_flag`/`clear_flag`.** Флаги работают; `flag_set` в `when` срабатывает.
41. **`set_tile`.** Клетка заменяется; визуально видна; участвует в коллизиях.
42. **`spawn`/`despawn`.** Мобы появляются/исчезают.
43. **`show_message`.** Сообщение отображается с duration.
44. **`victory`/`defeat`.** Немедленное завершение.

### 10.6. Условия победы/поражения

45. **`victory_conditions`.** Победа только при `reach_exit` + `kill_all 🐉`.
46. **`defeat_conditions`.** Поражение при `hp_zero` или `time_limit_exceeded`.
47. **Приоритет.** Если оба выполнены в одном кадре — победа.
48. **Совместимость.** Если `victory_conditions` не заданы — победа по достижении `exit` (как в блоке 8).

### 10.7. Интеграция с админкой

49. **UI «Атаки игрока».** Блок на вкладке «Настройки»; все поля редактируются.
50. **UI «Игрок».** `lives`, `health_max`, `dodge`, `parry`.
51. **UI «Очки».** `scoring`; combo и time_bonus.
52. **UI «Боевые свойства».** Блок в форме моба.
53. **UI «Свойства снаряда».** Блок в форме `projectile`.
54. **UI «Триггеры».** Секция в редакторе уровня; список, форма, «Проверить триггеры».
55. **UI «Условия победы/поражения».** В параметрах уровня.
56. **«Тест поведения».** Модалка с предпросмотром; моб действует по выбранной модели.
57. **Валидация.** Все проверки §7 работают; при ошибке — блокировка сохранения с сообщением у поля.

### 10.8. Безопасность и аудит

58. **XSS.** `show_message.text` с `<script>` — экранировано.
59. **CSRF.** POST на создание триггера без токена — 403.
60. **Аудит.** Все операции записаны в `audit.log`.
61. **Лимиты.** >50 триггеров — ошибка; >10 действий — ошибка; >5 вложенных — ошибка; глубина >2 — ошибка.

### 10.9. Обратная совместимость

62. **Старые игры** без `player_attacks`, `player.lives`, `scoring`, `triggers`, `victory_conditions` — работают как раньше.
63. **Критерии блоков 1–9** выполняются.

---

## 11. Оценка

**Ориентир:** 4–5 человеко-дней на разработчика (или ~1,5 рабочих дня с ИИ-ассистентом).

Основное время:

- **Боевая модель** — формулы урона, применение к игроку/мобу, death flow (≈0,5 дня).
- **Атаки игрока** — melee/ranged/stomp/special (4 механики × 3 движка) (≈1 день).
- **UI «Атаки игрока», «Игрок», «Очки»** — динамические формы (≈0,7 дня).
- **UI «Боевые свойства» моба и «Свойства снаряда»** — расширение формы (≈0,4 дня).
- **UI «Триггеры»** — динамические формы `when` (13 типов) и `then` (15 типов); `all_of`/`any_of` (≈1 день).
- **Валидация** — 25+ правил (≈0,5 дня).
- **«Тест поведения»** — отладочный режим (≈0,5 дня).
- **«Проверить триггеры»** — отладочный режим (≈0,3 дня).
- **Отладка и тестирование** (≈0,5 дня).

---

## 12. Что не делаем в блоке 10 и почему

- **Инвентарь и смена оружия** — вне объёма; оружие фиксировано в `player_attacks`.
- **Дерево навыков / прокачка** — вне объёма (RPG-механика).
- **Стихии / elemental damage** — вне объёма (требует UI инвентаря).
- **Status effects (poison, burn, slow)** — вне объёма (требует сложной модели состояний).
- **Parry / dodge как обязательные** — поля в схеме есть, но `enabled: false` по умолчанию; реализуются, если появятся конкретные запросы.
- **Мультиплеерный бой** — вне объёма.
- **Запись «эталонного прохождения»** — блок 13 (опционально).
- **Полная реализация `cast`, `throw`, `freeze`, `push`** — framework в блоке 11 (`actions.js`); конкретные эффекты — по потребности.
- **Boss-specific механики** — блок 5 (AI-модели `boss_*`) + блок 11 (реализация в `ai-core.js`).
- **Триггеры на уровне игры** — только на уровне уровня (`level.params.triggers[]`); game-level триггеры — вне объёма.

---

**Конец блока 10 из 13.**

Следующий блок — **Движки** (архитектура `EngineBase`, `static-screen`, `vertical-scroll`, `horizontal-scroll`, общие модули `ai-core`, `patterns`, `actions`, `audio-core`, `input-core`, `renderer-core`, `legend-resolver`, `level-parser`, `validators`).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 11. Движки

## 1. Общее описание

**Три универсальных движка** по типу конструирования мира:

| Движок | Мир | Окно | Обслуживаемые жанры |
|---|---|---|---|
| `static-screen` | Один экран, без скролла | 32×24 | Lode Runner-подобные, Arcade adventure, Pac-Man-подобные, Bomberman-подобные, Bubble Bobble-подобные, головоломки |
| `vertical-scroll` | Мир 32×N (N ∈ [24, 500]), вертикальный скролл | 32×24 | River Raid, Galaga, Xevious, Scramble, 1942 |
| `horizontal-scroll` | Мир M×24 (M ∈ [32, 800]), горизонтальный скролл | 32×24 | Super Mario Bros., Myth, Wonder Boy, Ghosts'n Goblins |

**Ключевые принципы:**

- **Data-driven.** Поведение игры полностью определяется `genre.engine_config` + `game.engine_overrides` + `game.legend` + `game.levels` + `game.controls` + `game.sounds`. Движок не содержит данных конкретной игры.
- **Единое ядро.** Общие модули (`shared/`) используются всеми тремя движками.
- **Единый экран 32×24.** Все три движка рендерят в окно 32×24 ячейки. Мир скролл-игр больше экрана, но окно — всегда 32×24.
- **Расширяемость.** Новый жанр добавляется через `genre_config` + легенду, без изменения кода движка.
- **Один контракт `EngineBase`** для всех трёх движков — публичная страница (`/play/...`) не знает, какой движок используется.

---

## 2. Архитектура

### 2.1. Структура

```
public/assets/engines/
├── registry.js                 # ENGINES = {'static-screen': StaticScreenEngine, ...}
├── boot.js                     # загрузчик публичной страницы
└── shared/
    ├── engine-base.js          # базовый класс (контракт)
    ├── audio-core.js           # Web Audio API
    ├── input-core.js           # KeyboardEvent.code → actions
    ├── renderer-core.js        # canvas, offscreen, drawCell/drawEntity
    ├── legend-resolver.js      # effective_legend
    ├── level-parser.js         # grid → anchors + singles
    ├── ai-core.js              # AI_REGISTRY (50 моделей)
    ├── patterns.js             # PATTERNS (50 паттернов)
    ├── actions.js              # ACTIONS (14 типов)
    └── validators.js           # валидация уровня перед запуском

static-screen/
├── index.js                    # export class StaticScreenEngine
├── engine.js                   # игровой цикл, физика
├── rules.js                    # специфика (копание, лестницы, перекладины)
└── modes.js                    # gravity / top_down_*

vertical-scroll/
├── index.js
├── engine.js
├── scroll.js                   # прокрутка мира
└── procedural.js               # процедурная генерация мира

horizontal-scroll/
├── index.js
├── engine.js
├── camera.js                   # follow / room / fixed
└── parallax.js                 # фоновые слои
```

### 2.2. Регистрация и загрузка

**`registry.js`:**

```js
import { StaticScreenEngine } from './static-screen/index.js';
import { VerticalScrollEngine } from './vertical-scroll/index.js';
import { HorizontalScrollEngine } from './horizontal-scroll/index.js';

export const ENGINES = {
  'static-screen': StaticScreenEngine,
  'vertical-scroll': VerticalScrollEngine,
  'horizontal-scroll': HorizontalScrollEngine,
};
```

**`boot.js`:**

```js
import { ENGINES } from './registry.js';

async function boot() {
  // 1. Читаем bootstrap JSON из <script type="application/json">
  const bootstrap = JSON.parse(document.getElementById('bootstrap').textContent);

  // 2. Предзагружаем банк эмодзи
  const bankMap = await loadEmojiBank('/api/emoji-bank.json');

  // 3. Резолвим эффективные конфиги
  const effectiveConfig = {
    ...bootstrap.genre.engine_config,
    ...bootstrap.game.engine_overrides,
  };
  const effectiveLegend = {
    ...bootstrap.genre.default_legend,
    ...bootstrap.game.legend,
  };

  // 4. Валидация уровня
  const errors = validateLevel(bootstrap.level, effectiveLegend, effectiveConfig, bootstrap.genre.engine);
  if (errors.length > 0) {
    showError(errors[0].message);
    return;
  }

  // 5. Создаём движок
  const EngineClass = ENGINES[bootstrap.genre.engine];
  if (!EngineClass) {
    showError('Движок не поддерживается');
    return;
  }

  const engine = new EngineClass({
    config: effectiveConfig,
    game: bootstrap.game,
    level: bootstrap.level,
    legend: effectiveLegend,
    genre: bootstrap.genre,
    canvas: document.getElementById('game'),
    hud: document.getElementById('hud'),
    overlay: document.getElementById('overlay'),
    bankMap,
  });

  // 6. Инициализация и запуск
  try {
    await engine.init();
    engine.start();
  } catch (e) {
    showError(e.message);
  }
}

boot();
```

### 2.3. Контракт `EngineBase`

Каждый движок наследуется от `EngineBase`:

```js
export class EngineBase {
  constructor({ config, game, level, legend, genre, canvas, hud, overlay, bankMap }) {
    this.config = config;
    this.game = game;
    this.level = level;
    this.legend = legend;
    this.genre = genre;
    this.canvas = canvas;
    this.ctx = canvas.getContext('2d');
    this.hud = hud;
    this.overlay = overlay;
    this.bankMap = bankMap;

    this.state = 'idle';         // idle | running | paused | victory | defeat
    this.world = null;           // {player, mobs, projectiles, pickups, tiles, ...}
    this.frameCount = 0;
    this.levelStartTime = 0;
    this._listeners = { victory: [], defeat: [] };
  }

  async init() { throw new Error('init() not implemented'); }
  start() { throw new Error('start() not implemented'); }
  stop() { throw new Error('stop() not implemented'); }
  pause() { this.state = 'paused'; }
  resume() { if (this.state === 'paused') this.state = 'running'; }
  destroy() { this.stop(); this._listeners = {}; }

  onVictory(cb) { this._listeners.victory.push(cb); }
  onDefeat(cb) { this._listeners.defeat.push(cb); }

  _emitVictory(payload) { this._listeners.victory.forEach(cb => cb(payload)); }
  _emitDefeat(payload) { this._listeners.defeat.forEach(cb => cb(payload)); }
}
```

### 2.4. Порядок инициализации (`init()`)

Общая часть для всех движков (в `EngineBase` или хелпере):

1. **`renderer-core.init(canvas, bankMap)`** — настройка контекста, предзагрузка.
2. **`input-core.init(game.controls, config.input_responsiveness)`** — маппинг `KeyboardEvent.code → action`.
3. **`audio-core.init(game.sounds, config.sound_volume, config.sound_enabled)`** — регистрация звуков.
4. **`level-parser.parse(level.grid, legend)`** — `{anchors, singles}` с композитами.
5. **`world` — инстанциация сущностей**:
   - игрок (из `start`);
   - мобы (из `mob`);
   - предметы (из `pickup`);
   - снаряды (пустой массив);
   - выход (из `exit`);
   - статические тайлы (offscreen canvas).
6. **`renderer-core.renderStatic(world)`** — предрендер статики.
7. **Триггеры** — `this.triggerState = new Map()` для отслеживания `once`.
8. **`levelStartTime = performance.now()`**.

### 2.5. Игровой цикл

**Fixed timestep** — обязательное требование для детерминизма:

```js
start() {
  this.state = 'running';
  this.levelStartTime = performance.now();
  let lastTime = performance.now();
  let acc = 0;
  const STEP_MS = 1000 / this.config.fps;

  const frame = (now) => {
    if (this.state === 'stopped') return;
    this._rafId = requestAnimationFrame(frame);

    if (this.state === 'running') {
      acc += now - lastTime;
      while (acc >= STEP_MS) {
        this.update(STEP_MS / 1000);
        acc -= STEP_MS;
        this.frameCount++;
      }
    }
    lastTime = now;

    this.render();
  };

  this._rafId = requestAnimationFrame(frame);
}

stop() {
  this.state = 'stopped';
  if (this._rafId) cancelAnimationFrame(this._rafId);
}
```

**`update(dt)`** — общий скелет (каждый движок переопределяет шаги):

```
1. Обработка ввода (input-core)
2. Обновление игрока (движок-специфично)
3. Обновление мобов (ai-core + patterns + actions)
4. Обновление снарядов
5. Разрешение урона (боевая система)
6. Обновление пикапов, коллизий
7. Обновление ям / временных эффектов (static-screen)
8. Обновление скролла / камеры (scroll-движки)
9. Проверка триггеров
10. Проверка условий победы/поражения
11. Обновление анимаций (startTime клеток)
```

**`render()`** — общий скелет:

```
1. ctx.clearRect
2. drawImage(staticCanvas) — статика
3. Отрисовка динамики: игрок, мобы, снаряды, пикапы
4. Отрисовка анимированных клеток поверх статики
5. Отрисовка HUD (если show_hud)
6. Отрисовка overlay (victory/defeat/pause)
```

### 2.6. `speed_scale`

**Единая точка применения** — все скоростные константы движка умножаются на `speed_scale` при инициализации:

```js
const scale = this.config.speed_scale || 1.0;
this.speeds = {
  move: this.config.physics.move_speed * scale,
  gravity: this.config.physics.gravity * scale,
  jump: this.config.physics.jump_power * scale,
  climb: this.config.physics.climb_speed * scale,
  swim: this.config.physics.swim_speed * scale,
  mobDefault: 0.4 * scale,
  scroll: this.config.scroll_speed * scale,
  projectile: (base) => base * scale,
};
```

Не влияет: `hole_duration_ms`, `trap_duration_ms`, `invulnerability_ms`, `cooldown_ms`, звуки, анимации (только `animation_speed_scale`).

### 2.7. `sprite_flicker`

При каждом кадре для каждого **движущегося** спрайта (игрок, мобы, снаряды):

```js
if (this.config.sprite_flicker && Math.random() < this.config.sprite_flicker_intensity) {
  return; // пропуск отрисовки спрайта в этом кадре
}
```

Реализация воспроизводит атрибутный клэш ZX Spectrum.

### 2.8. `input_responsiveness`

Буферизация ввода в `input-core`:

```js
const delayFrames = Math.round((1 - config.input_responsiveness) * 5);
// event_queue = [{action, frame: this.frameCount + delayFrames, type: 'down'|'up'}]
// в update(): применяем события, у которых frame <= this.frameCount
```

`1.0` — задержка 0; `0.5` — ~2.5 кадра; `0.0` — 5 кадров.

### 2.9. `animation_speed_scale`

`effective_duration = frame.duration_ms / animation_speed_scale`.

---

## 3. Общие модули

### 3.1. `legend-resolver.js`

```js
export function resolveLegend(genre, game) {
  return {
    ...(genre.default_legend || {}),
    ...(game.legend || {}),
  };
}

export function getRole(legend, emoji) {
  const def = legend[emoji];
  return def ? def.role : null;
}

export function isSolid(legend, emoji) {
  const def = legend[emoji];
  return def ? (def.solid === true) : false;
}
```

**Правило:** эффективная легенда = `{...genre.default_legend, ...game.legend}`. Emoji из `game.legend` переопределяют дефолтные.

### 3.2. `level-parser.js`

```js
export function parseGrid(grid, legend) {
  const ROWS = grid.length;
  const COLS = splitGraphemes(grid[0]).length;

  // 1. Разбор строк в grapheme-массивы
  const cells = grid.map(row => splitGraphemes(row));

  // 2. Сканирование якорей композитов
  const anchors = [];
  const occupied = new Set();

  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      const emoji = cells[r][c];
      const def = legend[emoji];
      if (def && def.composite && def.composite.sprites[0][0] === emoji) {
        const w = def.composite.width;
        const h = def.composite.height;
        // проверка границ
        if (r + h > ROWS || c + w > COLS) {
          throw new Error(`Композит ${emoji} выходит за границы в (${r},${c})`);
        }
        // проверка перекрытия
        for (let i = 0; i < h; i++) {
          for (let j = 0; j < w; j++) {
            const key = `${r + i},${c + j}`;
            if (occupied.has(key)) {
              throw new Error(`Композиты пересекаются в (${r + i},${c + j})`);
            }
            occupied.add(key);
          }
        }
        // проверка соответствия спрайтов
        for (let i = 0; i < h; i++) {
          for (let j = 0; j < w; j++) {
            if (cells[r + i][c + j] !== def.composite.sprites[i][j]) {
              throw new Error(`Несоответствие в (${r + i},${c + j}): ожидалось ${def.composite.sprites[i][j]}`);
            }
          }
        }
        anchors.push({
          kind: 'anchor',
          emoji, def, r, c,
          width: w, height: h,
          x: c, y: r,
          sprites: def.composite.sprites,
        });
      }
    }
  }

  // 3. Сбор одиночных 1×1
  const singles = [];
  for (let r = 0; r < ROWS; r++) {
    for (let c = 0; c < COLS; c++) {
      if (occupied.has(`${r},${c}`)) continue;
      const emoji = cells[r][c];
      const def = legend[emoji];
      if (!def) {
        throw new Error(`Emoji ${emoji} отсутствует в легенде в (${r},${c})`);
      }
      singles.push({
        kind: 'single',
        emoji, def, r, c,
        width: 1, height: 1,
        x: c, y: r,
        sprites: [[emoji]],
      });
    }
  }

  return { anchors, singles, ROWS, COLS };
}

function splitGraphemes(str) {
  const segmenter = new Intl.Segmenter('en', { granularity: 'grapheme' });
  return Array.from(segmenter.segment(str.replace(/ /g, ''))).map(s => s.segment);
}
```

### 3.3. `renderer-core.js`

```js
let canvas, ctx, bankMap;
let staticCanvas, sctx;

export function init(canvasEl, bankMapRef, cols, rows, cellSize) {
  canvas = canvasEl;
  ctx = canvas.getContext('2d');
  ctx.imageSmoothingEnabled = false;
  bankMap = bankMapRef;

  staticCanvas = document.createElement('canvas');
  staticCanvas.width = cols * cellSize;
  staticCanvas.height = rows * cellSize;
  sctx = staticCanvas.getContext('2d');
  sctx.imageSmoothingEnabled = false;
}

export function drawCell(context, emoji, x, y, size, now, startTime) {
  const entry = bankMap && bankMap.get(emoji);
  if (!entry) {
    context.font = `${Math.floor(size * 0.85)}px sans-serif`;
    context.textAlign = 'center';
    context.textBaseline = 'middle';
    context.fillText(emoji, x + size / 2, y + size / 2);
    return;
  }
  if (entry.type === 'static') {
    context.drawImage(entry.image, x, y, size, size);
    return;
  }
  // animation
  const frame = pickFrame(entry, now, startTime);
  context.drawImage(frame.image, x, y, size, size);
}

function pickFrame(anim, now, startTime) {
  const elapsed = now - startTime;
  let t;
  if (anim.loop_mode === 'once') {
    t = Math.min(elapsed, anim.total_duration_ms - 1);
  } else if (anim.loop_mode === 'ping_pong') {
    const cycle = anim.total_duration_ms * 2;
    const pos = elapsed % cycle;
    t = pos < anim.total_duration_ms ? pos : (cycle - pos);
  } else {
    t = elapsed % anim.total_duration_ms;
  }
  let acc = 0;
  for (const f of anim.frames) {
    acc += f.duration_ms;
    if (t < acc) return f;
  }
  return anim.frames[anim.frames.length - 1];
}

export function renderStatic(singles, anchors, cols, rows, cellSize) {
  sctx.clearRect(0, 0, staticCanvas.width, staticCanvas.height);
  const skip = new Set(); // анимированные emoji не предрендерим
  for (const s of singles) {
    const entry = bankMap && bankMap.get(s.emoji);
    if (entry && entry.type === 'animation') { skip.add(s); continue; }
    drawCell(sctx, s.emoji, s.c * cellSize, s.r * cellSize, cellSize, 0, 0);
  }
  for (const a of anchors) {
    for (let i = 0; i < a.height; i++) {
      for (let j = 0; j < a.width; j++) {
        const emoji = a.sprites[i][j];
        const entry = bankMap && bankMap.get(emoji);
        if (entry && entry.type === 'animation') continue;
        drawCell(sctx, emoji, (a.c + j) * cellSize, (a.r + i) * cellSize, cellSize, 0, 0);
      }
    }
  }
}

export function blitStatic(ctx, cameraX, cameraY) {
  ctx.drawImage(staticCanvas, -cameraX * CELL, -cameraY * CELL);
}
```

### 3.4. `audio-core.js`

```js
let ac, soundMap, masterVolume, enabled;

export function init(sounds, volume, isEnabled) {
  soundMap = new Map(sounds.map(s => [s.event, s]));
  masterVolume = volume;
  enabled = isEnabled;
}

export function ensureAudio() {
  if (!ac) ac = new (window.AudioContext || window.webkitAudioContext)();
  if (ac.state === 'suspended') ac.resume();
  return ac;
}

export function playSound(event) {
  if (!enabled) return;
  const cfg = soundMap.get(event);
  if (!cfg) return;
  try {
    const context = ensureAudio();
    const osc = context.createOscillator();
    const gain = context.createGain();

    osc.type = cfg.wave === 'noise' ? 'sawtooth' : cfg.wave;
    osc.frequency.value = cfg.frequency_hz;
    if (cfg.frequency_end_hz) {
      osc.frequency.exponentialRampToValueAtTime(
        cfg.frequency_end_hz,
        context.currentTime + cfg.duration_ms / 1000
      );
    }

    const vol = Math.min(cfg.volume, masterVolume);
    gain.gain.value = vol;

    osc.connect(gain);
    gain.connect(context.destination);
    osc.start();

    applyEnvelope(gain, cfg.envelope, cfg.duration_ms, vol);
    osc.stop(context.currentTime + cfg.duration_ms / 1000);
  } catch (e) {
    // Тихо игнорируем — не блокируем игру
  }
}

function applyEnvelope(gain, envelope, durationMs, peakVolume) {
  const now = gain.context.currentTime;
  const end = now + durationMs / 1000;
  if (envelope === 'linear') {
    gain.gain.linearRampToValueAtTime(0.0001, end);
  } else if (envelope === 'decay') {
    gain.gain.exponentialRampToValueAtTime(0.0001, end);
  } else if (envelope === 'pluck') {
    gain.gain.exponentialRampToValueAtTime(peakVolume * 0.3, now + durationMs / 3000);
    gain.gain.exponentialRampToValueAtTime(0.0001, end);
  }
}

export function unlockOnFirstInteraction() {
  const handler = () => {
    ensureAudio();
    document.removeEventListener('keydown', handler);
    document.removeEventListener('pointerdown', handler);
  };
  document.addEventListener('keydown', handler);
  document.addEventListener('pointerdown', handler);
}
```

### 3.5. `input-core.js`

```js
let actionMap = new Map();     // KeyboardEvent.code → action
let state = {};                 // action → bool
let queue = [];                 // буфер нажатий
let delayFrames = 0;
let currentFrame = 0;

export function init(controls, responsiveness) {
  state = {};
  queue = [];
  actionMap = new Map();
  for (const [action, codes] of Object.entries(controls)) {
    for (const code of codes) actionMap.set(code, action);
    state[action] = false;
  }
  delayFrames = Math.round((1 - responsiveness) * 5);

  document.addEventListener('keydown', e => {
    if (e.repeat) return;
    const action = actionMap.get(e.code);
    if (!action) return;
    e.preventDefault();
    queue.push({ action, type: 'down', frame: currentFrame + delayFrames });
  });

  document.addEventListener('keyup', e => {
    const action = actionMap.get(e.code);
    if (!action) return;
    e.preventDefault();
    queue.push({ action, type: 'up', frame: currentFrame + delayFrames });
  });
}

export function tick() {
  currentFrame++;
  while (queue.length && queue[0].frame <= currentFrame) {
    const ev = queue.shift();
    state[ev.action] = (ev.type === 'down');
  }
}

export function isPressed(action) {
  return state[action] === true;
}

export function reset() {
  for (const k in state) state[k] = false;
  queue = [];
}
```

### 3.6. `ai-core.js`

Реестр 50 AI-моделей. Каждая — функция `(mob, world, dt) → void`.

```js
export const AI_REGISTRY = {

  stationary: (mob, world, dt) => {
    // ничего
  },

  patrol_horizontal: (mob, world, dt) => {
    const speed = mob.ai_params.speed * world.speeds.scale;
    mob.x += mob.dir * speed * dt * 60;
    const ahead = isSolidAt(world, mob.x + mob.dir * 0.6, mob.y);
    const below = isSolidAt(world, mob.x + mob.dir * 0.6, mob.y + 1);
    const edge = !below && !isClimbableAt(world, mob.x + mob.dir * 0.6, mob.y);
    if (ahead || (edge && mob.ai_params.turn_on_edge)) {
      mob.dir *= -1;
    }
  },

  patrol_vertical: (mob, world, dt) => {
    const speed = mob.ai_params.speed * world.speeds.scale;
    mob.y += mob.dir * speed * dt * 60;
    const ahead = isSolidAt(world, mob.x, mob.y + mob.dir * 0.6);
    if (ahead) mob.dir *= -1;
  },

  patrol_waypoints: (mob, world, dt) => {
    const target = mob.ai_params.waypoints[mob.waypoint_index];
    moveToward(mob, target, mob.ai_params.speed * world.speeds.scale, dt);
    if (distance(mob, target) < 0.1) {
      mob.waypoint_index = (mob.waypoint_index + 1) % mob.ai_params.waypoints.length;
    }
  },

  wander: (mob, world, dt) => {
    mob.wanderTimer = (mob.wanderTimer || 0) - dt * 1000;
    if (mob.wanderTimer <= 0) {
      mob.dir = Math.floor(Math.random() * 4);
      mob.wanderTimer = mob.ai_params.change_dir_ms;
    }
    moveDirection(mob, mob.dir, mob.ai_params.speed * world.speeds.scale, dt);
  },

  chase: (mob, world, dt) => {
    const target = world.player;
    moveToward(mob, target, mob.ai_params.speed * world.speeds.scale, dt);
    handleWall(mob, world, mob.ai_params.turn_on_wall);
  },

  chase_with_vision: (mob, world, dt) => {
    const player = world.player;
    const dist = Math.hypot(player.x - mob.x, player.y - mob.y);
    if (mob._chasing) {
      if (dist > mob.ai_params.lose_range) mob._chasing = false;
    } else {
      if (dist <= mob.ai_params.vision_range) mob._chasing = true;
    }
    const target = mob._chasing ? player : mob._home;
    moveToward(mob, target, mob.ai_params.speed * world.speeds.scale, dt);
    handleWall(mob, world, mob.ai_params.turn_on_wall);
  },

  // ... 44 остальных контроллера
};

export function runAI(mob, world, dt) {
  const model = mob.def.ai_model;
  if (!model) return;
  const fn = AI_REGISTRY[model];
  if (!fn) {
    // fallback
    AI_REGISTRY.patrol_horizontal(mob, world, dt);
    return;
  }
  fn(mob, world, dt);
}
```

**Все 50 моделей из блока 5** реализуются в `AI_REGISTRY` для движков, где их `supported_engines` включает соответствующий движок. Модели, не поддерживаемые движком, в `AI_REGISTRY` **отсутствуют** (валидация в админке блокирует выбор; fallback — патруль).

**`hybrid`-режим** (`behavior_mode: hybrid`): после `runAI` накладывается паттерн-модуляция:

```js
if (mob.behavior_mode === 'hybrid') {
  const base_x = mob.x, base_y = mob.y;
  runAI(mob, world, dt);
  const ai_x = mob.x, ai_y = mob.y;
  const patternPos = PATTERNS[mob.movement_pattern](mob.movement_params, (now - mob.spawnTime) / 1000, mob, world);
  mob.x = ai_x + (patternPos.x - mob.baseOffsetX);
  mob.y = ai_y + (patternPos.y - mob.baseOffsetY);
}
```

### 3.7. `patterns.js`

```js
export const PATTERNS = {

  straight_down: (p, t, mob) => ({
    x: mob.baseX,
    y: mob.baseY + p.speed * t,
  }),

  sine_horizontal: (p, t, mob) => ({
    x: mob.baseX + p.amplitude * Math.sin(2 * Math.PI * p.frequency_hz * t + (p.phase || 0)),
    y: mob.baseY + p.speed * t,
  }),

  spiral_in: (p, t, mob) => {
    const k = Math.min(t / (p.duration_ms / 1000), 1);
    const r = p.radius_start + (p.radius_end - p.radius_start) * k;
    const a = p.angular_speed * t * (p.direction === 'cw' ? 1 : -1);
    return {
      x: p.center_x + r * Math.cos(a),
      y: p.center_y + r * Math.sin(a),
    };
  },

  homing_curved: (p, t, mob, world) => {
    const target = world.player;
    const targetAngle = Math.atan2(target.y - mob.y, target.x - mob.x);
    const maxTurn = p.turn_rate_deg_per_sec * Math.PI / 180 * world.dt;
    const delta = Math.max(-maxTurn, Math.min(maxTurn, targetAngle - mob.angle));
    mob.angle += delta;
    return {
      x: mob.x + p.speed * Math.cos(mob.angle) * world.dt,
      y: mob.y + p.speed * Math.sin(mob.angle) * world.dt,
    };
  },

  // ... 46 остальных паттернов
};

export function runPattern(mob, world, now) {
  const fn = PATTERNS[mob.def.movement_pattern];
  if (!fn) return;
  const t = (now - mob.spawnTime) / 1000;
  const pos = fn(mob.movement_params, t, mob, world);
  mob.x = pos.x;
  mob.y = pos.y;
}
```

### 3.8. `actions.js`

Реестр 14 типов действий из блока 7, §3.5 и блока 10.

```js
export const ACTIONS = {

  shoot: (actor, world, action) => {
    // rate_ms проверяется в движке до вызова
    const projEmoji = action.projectile;
    const projDef = world.legend[projEmoji];
    const dir = action.direction === 'fixed'
      ? { x: action.vector.dx, y: action.vector.dy }
      : directionToward(actor, world.player);
    world.projectiles.push({
      emoji: projEmoji,
      def: projDef,
      x: actor.x, y: actor.y,
      vx: dir.x * projDef.speed,
      vy: dir.y * projDef.speed,
      owner: actor,
      spawnTime: performance.now(),
    });
  },

  melee: (actor, world, action) => {
    const targets = world.mobs.filter(m =>
      distance(actor, m) <= 1.5 && m !== actor
    );
    for (const m of targets) {
      applyDamage(m, action.damage, actor, { stun_ms: 0, knockback: 0 });
    }
  },

  spawn: (actor, world, action) => {
    // проверка max_alive
    const alive = world.mobs.filter(m => m.emoji === action.spawn_emoji).length;
    if (alive >= action.max_alive) return;
    spawnMob(world, action.spawn_emoji, actor.x, actor.y);
  },

  open: (actor, world, action) => {
    // открытие двери
    if (hasPickup(world.player, action.requires_id)) {
      unlockTileAt(actor.x, actor.y, world);
    }
  },

  heal: (actor, world, action) => {
    if (actor === world.player) {
      world.player.hp = Math.min(world.player.hp + action.amount, world.player.maxHp);
    } else {
      const target = findNearestAlly(actor, world);
      if (target) target.hp = Math.min(target.hp + action.amount, target.maxHp);
    }
  },

  damage_area: (actor, world, action) => {
    const targets = world.mobs.filter(m =>
      distance(actor, m) <= action.radius && m !== actor
    );
    for (const m of targets) {
      applyDamage(m, action.damage, actor, {});
    }
  },

  teleport: (actor, world, action) => {
    actor.x = action.to.col;
    actor.y = action.to.row;
  },

  freeze: (actor, world, action) => {
    const targets = world.mobs.filter(m =>
      distance(actor, m) <= action.radius && m !== actor
    );
    for (const m of targets) {
      m.frozen_until = performance.now() + action.duration_ms;
    }
  },

  push: (actor, world, action) => {
    const targets = world.mobs.filter(m =>
      distance(actor, m) <= action.radius && m !== actor
    );
    for (const m of targets) {
      const dir = action.direction === 'away'
        ? directionToward(actor, m)
        : directionToward(m, actor);
      m.knockback_vx = dir.x * 5;
      m.knockback_vy = dir.y * 5;
    }
  },

  // cast, throw, use, on_stomp, on_touch — аналогично
};
```

### 3.9. `validators.js`

```js
export function validateLevel(level, legend, config, engine) {
  const errors = [];
  const grid = level.grid;

  if (!Array.isArray(grid) || grid.length === 0) {
    errors.push({ message: 'Сетка уровня пуста' });
    return errors;
  }

  // 1. Проверка размеров по движку
  const cols = splitGraphemes(grid[0]).length;
  const rows = grid.length;
  if (engine === 'static-screen') {
    if (rows !== 24 || cols !== 32) {
      errors.push({ message: `static-screen требует ровно 24×32, получено ${rows}×${cols}` });
    }
  } else if (engine === 'vertical-scroll') {
    if (cols !== 32) errors.push({ message: `vertical-scroll требует 32 столбца, получено ${cols}` });
    if (rows < 24 || rows > 500) errors.push({ message: `vertical-scroll: строк 24–500, получено ${rows}` });
  } else if (engine === 'horizontal-scroll') {
    if (rows !== 24) errors.push({ message: `horizontal-scroll требует 24 строки, получено ${rows}` });
    if (cols < 32 || cols > 800) errors.push({ message: `horizontal-scroll: столбцов 32–800, получено ${cols}` });
  }

  // 2. Проверка строк на одинаковую длину
  for (let i = 0; i < grid.length; i++) {
    const rowCols = splitGraphemes(grid[i]).length;
    if (rowCols !== cols) {
      errors.push({ message: `Строка ${i + 1} имеет ${rowCols} столбцов, ожидалось ${cols}` });
    }
  }

  // 3. Парсинг (композиты, известность emoji)
  let parsed;
  try {
    parsed = parseGrid(grid, legend);
  } catch (e) {
    errors.push({ message: e.message });
    return errors;
  }

  // 4. Семантика по правилам жанра
  const rules = config.level_rules || {};
  const all = [...parsed.anchors, ...parsed.singles];
  const roles = all.map(e => e.def.role);

  if (rules.requires_start) {
    const startCount = roles.filter(r => r === 'start').length;
    if (startCount !== 1) {
      errors.push({ message: `Требуется ровно один start, найдено ${startCount}` });
    }
  }
  if (rules.requires_exit) {
    if (!roles.includes('exit')) errors.push({ message: 'Требуется минимум один exit' });
  }
  if (rules.requires_finish) {
    if (!roles.includes('exit')) errors.push({ message: 'Требуется минимум один finish' });
  }
  if (rules.min_pickups) {
    const pickups = roles.filter(r => r === 'pickup').length;
    if (pickups < rules.min_pickups) {
      errors.push({ message: `Требуется минимум ${rules.min_pickups} pickup, найдено ${pickups}` });
    }
  }

  return errors;
}

function splitGraphemes(str) {
  const segmenter = new Intl.Segmenter('en', { granularity: 'grapheme' });
  return Array.from(segmenter.segment(str.replace(/ /g, ''))).map(s => s.segment);
}
```

---

## 4. Движок `static-screen`

### 4.1. Режимы мира

Определяются `engine_config.movement_type`:

| `movement_type` | `view_mode` | Гравитация | Прыжки | Примеры |
|---|---|---|---|---|
| `gravity` | `side` | Да | Если `jump_power < 0` | Lode Runner, Bubble Bobble, Chuckie Egg |
| `top_down_4dir` | `top` | Нет | Нет | Pac-Man, Bomberman |
| `top_down_8dir` | `top` | Нет | Нет | Cauldron, Robin of the Wood |
| `top_down_4dir_no_diag` | `top` | Нет | Нет | Bomberman (строгая сетка) |

При `view_mode: top` — `physics.gravity = 0`, `physics.jump_power = 0` (автокоррекция с записью в `audit.log`).

### 4.2. Состояния игрока

`idle`, `walk`, `jump`, `fall`, `climb_up`, `climb_down`, `hang`, `swim`, `dig`, `attack`, `push`, `hurt`, `dead`, `victory`.

Переходы — по вводу, типу клетки, `movement_type`.

### 4.3. Физика `gravity`

```js
updatePlayerGravity(dt) {
  const player = this.world.player;
  const pc = Math.round(player.x);
  const pr = Math.round(player.y);

  const onLadder = this._isTileWithFlag(pc, pr, 'climbable');
  const underBar = this._isTileWithFlag(pc, pr - 1, 'hangable');
  const onGround = this._isTileSolid(pc, pr + 1) || this._isTileWithFlag(pc, pr + 1, 'climbable');

  // Вертикаль
  if (onLadder) {
    if (input.isPressed('up')) {
      player.y -= this.speeds.climb * dt * 60;
    } else if (input.isPressed('down')) {
      if (!this._isTileSolid(pc, pr + 1)) player.y += this.speeds.climb * dt * 60;
    }
    player.vy = 0;
  } else if (underBar && !input.isPressed('down')) {
    player.vy = 0;
  } else {
    player.vy += this.speeds.gravity * dt * 60;
    const newY = player.y + player.vy * dt;
    if (this._isTileSolid(pc, Math.round(newY + 0.5))) {
      if (player.vy > 0) {
        player.y = Math.round(player.y);
        player.onGround = true;
      }
      player.vy = 0;
    } else {
      player.y = newY;
      player.onGround = false;
    }
  }

  // Прыжок
  if (input.isPressed('jump') && player.onGround && this.config.physics.jump_power < 0) {
    player.vy = this.speeds.jump;
    player.onGround = false;
    playSound('jump');
  }

  // Горизонталь
  const dx = (input.isPressed('left') ? -1 : 0) + (input.isPressed('right') ? 1 : 0);
  if (dx !== 0) {
    const newX = player.x + dx * this.speeds.move * dt * 60;
    if (!this._isTileSolid(Math.round(newX + 0.5), Math.round(player.y))) {
      player.x = newX;
    }
  }

  // Копание
  if (input.isPressed('dig_left')) this._dig(-1);
  if (input.isPressed('dig_right')) this._dig(1);

  // Сбор / выход / смерть
  this._collectPickup(pc, pr);
  this._checkExit(pc, pr);
  this._checkEnemyContact(pc, pr);
}
```

### 4.4. Физика `top_down_*`

```js
updatePlayerTopDown(dt) {
  const player = this.world.player;
  let dx = 0, dy = 0;

  if (input.isPressed('left')) dx -= 1;
  if (input.isPressed('right')) dx += 1;
  if (input.isPressed('up')) dy -= 1;
  if (input.isPressed('down')) dy += 1;

  // Нормализация для 8dir
  if (this.config.movement_type === 'top_down_8dir' && dx !== 0 && dy !== 0) {
    const n = Math.sqrt(2);
    dx /= n; dy /= n;
  }
  // Запрет диагоналей для 4dir
  if (this.config.movement_type === 'top_down_4dir_no_diag' && dx !== 0 && dy !== 0) {
    dy = 0;
  }

  const speed = this.speeds.move * dt * 60;
  const newX = player.x + dx * speed;
  const newY = player.y + dy * speed;

  if (!this._isTileSolid(Math.round(newX + 0.5), Math.round(player.y))) player.x = newX;
  if (!this._isTileSolid(Math.round(player.x), Math.round(newY + 0.5))) player.y = newY;

  // Плавание
  if (this._isTileWithFlag(Math.round(player.x), Math.round(player.y), 'swimmable')) {
    player.state = 'swim';
  }

  this._collectPickup(Math.round(player.x), Math.round(player.y));
  this._checkExit(Math.round(player.x), Math.round(player.y));
  this._checkEnemyContact(Math.round(player.x), Math.round(player.y));
}
```

### 4.5. Копание (`_dig(dx)`)

```js
_dig(dx) {
  const player = this.world.player;
  if (!player.onGround && !this._isTileWithFlag(Math.round(player.x), Math.round(player.y), 'climbable')) {
    return;
  }
  const tc = Math.round(player.x) + dx;
  const tr = Math.round(player.y) + 1;

  if (this._isTileWithFlag(tc, tr, 'diggable')) {
    this._setTile(tc, tr, null); // яма
    this.world.holes.set(`${tr},${tc}`, performance.now() + this.config.hole_duration_ms);
    playSound('dig');
  }
}
```

**Закрытие ямы:** в `update()`:

```js
for (const [key, expiresAt] of this.world.holes) {
  if (now >= expiresAt) {
    const [r, c] = key.split(',').map(Number);
    this._setTile(c, r, this.world.originalTileAt(c, r)); // восстановить
    this.world.holes.delete(key);
    // если игрок/моб внутри — смерть
    if (this._playerInside(c, r)) this._killPlayer();
    for (const m of this.world.mobs) if (this._entityInside(m, c, r)) this._killMob(m);
  }
}
```

### 4.6. Рендер

- `drawImage(staticCanvas)` — фон, препятствия, статичные пикапы.
- Динамика: игрок, мобы (с учётом `behavior_mode`), снаряды.
- Анимированные emoji в сетке — поверх статики (см. блок 6).
- Ямы — чёрные прямоугольники.

### 4.7. AI мобов

`runAI(mob, world, dt)` из `ai-core.js`. Мобы с `behavior_mode: trajectory` — `runPattern`. Мобы с `hybrid` — оба.

### 4.8. Специфичные параметры

- `hole_duration_ms`, `trap_duration_ms`.
- `physics.climb_speed`, `physics.swim_speed`.

---

## 5. Движок `vertical-scroll`

### 5.1. Мир

Мир **32×N**, N ∈ [24, 500]. Генерация:

- **`world_generation: "grid"`** — `level.grid` содержит N строк по 32 emoji.
- **`world_generation: "procedural"`** — `level.grid` содержит шаблон первого экрана + `params.seed` + `params.segments`. Процедурная генерация берегов по `width_pattern`. Детерминирована при одном `seed`.

### 5.2. Скролл

Принудительный, `scroll_speed` тайлов/сек. Мир прокручивается; игрок в нижней части экрана, движется в пределах `vertical_range` от базовой позиции.

```js
updateScroll(dt) {
  this.world.scrollOffset += this.speeds.scroll * dt;
  // камера показывает окно 32×24 из мира
  this.cameraY = this.world.scrollOffset;
}
```

### 5.3. Игрок

- `left`/`right` — горизонталь.
- `up`/`down` — вертикаль в пределах `vertical_range` от базовой позиции.
- `fire` — стрельба (`player_attacks.ranged` из блока 10, или `combat.player_fire_rate_ms` из блока 9 как fallback).
- **Топливо:** `fuel_capacity > 0` — убывание; `fuel_consumption_per_sec`; при 0 — game over. Пополнение на `pickup` с `pickup_id: "fuel"`.
- Столкновение с `obstacle` (`solid`) — смерть.

### 5.4. Паттерны и формации

- Мобы используют `movement_pattern` из `patterns.js`.
- **Формации:** `leader` + `follower`. Ведомые наследуют паттерн лидера с offset по `formation.type` и `spacing`. При потере лидера — `straight_down`.

### 5.5. Сегменты

При `world_generation: "procedural"` — `params.segments[]` содержит объекты: `fuel_depot`, `enemy_plane`, `helicopter`, `ship`, `bridge`. `bridge` — конец сегмента; вход в него — переход к следующему или победа.

### 5.6. Рендер

- `drawImage(staticCanvas, 0, -scrollOffset * CELL)` — сдвиг по Y.
- Динамика — с учётом `scrollOffset`.
- Виртуализация: рендерятся только видимые тайлы.

---

## 6. Движок `horizontal-scroll`

### 6.1. Мир

Мир **M×24**, M ∈ [32, 800]. `level.grid` — 24 строки по M emoji.

### 6.2. Камера

- **`follow`** — камера плавно следует за игроком: `cameraX += (targetX - cameraX) * camera_lerp * dt * 60`.
- **`room`** — камера сдвигается на целый экран при выходе за край.
- **`fixed`** — камера неподвижна (для коротких уровней).

`targetX = player.x - VIEWPORT_COLS / 2`, clamp `[0, M - VIEWPORT_COLS]`.

### 6.3. Игрок

- `left`/`right` — движение.
- `jump` — прыжок с `jump_power`; `variable_jump` — короткое нажатие = низкий прыжок; `coyote_time_ms`; `jump_buffer_ms`; `double_jump`.
- `fire` — стрельба (если `player_attacks.ranged` или `player_attacks.melee`).
- `air_control` — управление в воздухе.

```js
updatePlayer(dt) {
  const p = this.world.player;
  const now = performance.now();

  // Coyote time
  if (p.onGround) p.lastGroundedAt = now;
  const canJump = (now - p.lastGroundedAt) < this.config.physics.coyote_time_ms || p.onGround;

  // Jump buffer
  if (input.isPressed('jump')) p.jumpBufferedAt = now;
  const wantsJump = (now - (p.jumpBufferedAt || 0)) < this.config.physics.jump_buffer_ms;

  if (wantsJump && canJump) {
    p.vy = this.speeds.jump;
    p.onGround = false;
    p.jumpBufferedAt = 0;
    playSound('jump');
  }

  // Variable jump
  if (!input.isPressed('jump') && p.vy < 0 && this.config.physics.variable_jump) {
    p.vy *= 0.85;
  }

  // Double jump
  if (wantsJump && !p.onGround && this.config.physics.double_jump && !p.usedDoubleJump) {
    p.vy = this.speeds.jump * 0.85;
    p.usedDoubleJump = true;
    p.jumpBufferedAt = 0;
    playSound('jump');
  }

  // Гравитация
  p.vy = Math.min(p.vy + this.speeds.gravity * dt * 60, this.config.physics.max_fall_speed);

  // Горизонталь
  const airControl = p.onGround ? 1 : this.config.physics.air_control;
  const dx = (input.isPressed('left') ? -1 : 0) + (input.isPressed('right') ? 1 : 0);
  if (dx !== 0) {
    const newX = p.x + dx * this.speeds.move * airControl * dt * 60;
    if (!this._isTileSolid(Math.round(newX + 0.5), Math.round(p.y))) p.x = newX;
  }

  // Вертикаль
  const newY = p.y + p.vy * dt;
  if (this._isTileSolid(Math.round(p.x), Math.round(newY + 0.5))) {
    if (p.vy > 0) {
      p.y = Math.round(p.y);
      p.onGround = true;
      p.usedDoubleJump = false;
    }
    p.vy = 0;
  } else {
    p.y = newY;
    p.onGround = false;
  }

  this._checkStomp();
  this._checkEnemyContact();
  this._checkExit();
}
```

### 6.4. `on_stomp`

Действие для мобов: прыжок на голову моба.

```js
_checkStomp() {
  const p = this.world.player;
  if (p.vy <= 0) return;
  for (const m of this.world.mobs) {
    if (this._overlaps(p, m) && p.y < m.y - 0.5) {
      const stomp = this.config.player_attacks.stomp;
      applyDamage(m, stomp.damage, p, { stun_ms: stomp.stun_ms });
      if (stomp.morph_to) {
        morphMob(m, stomp.morph_to);
      }
      p.vy = stomp.bounce_power;
      playSound('hit');
      return;
    }
  }
}
```

### 6.5. Чекпоинты

`level.params.checkpoints[]` — массив `{col, row}`. При смерти игрока — респавн на последнем пройденном чекпоинте.

```js
checkCheckpoints() {
  const p = this.world.player;
  for (const cp of this.level.params.checkpoints || []) {
    if (Math.abs(p.x - cp.col) < 0.6 && Math.abs(p.y - cp.row) < 1.5) {
      this.lastCheckpoint = cp;
    }
  }
}
```

### 6.6. Трубы-телепорты

`collider` с `actions: [{type: "teleport", to: {col, row}}]`. При входе игрока в клетку трубы и нажатии `down` — телепорт.

### 6.7. Параллакс

`level.params.background_layer` — массив строк (задний слой); рендерится со `scrollFactor: 0.5`.

---

## 7. Общие системы

### 7.1. Композиты

- **Парсинг** — `level-parser.js` (§3.2).
- **Коллизии** — по bounding box.
- **AI** — по центру композита.
- **Рендер** — все `N` клеток.

### 7.2. Боевая система

Реализуется в `applyDamage(target, damage, source, effects)`:

```js
function applyDamage(target, rawDamage, source, effects) {
  // Проверка shield_front
  if (target.def.shield_front && isInFrontCone(target, source)) {
    rawDamage *= target.def.shield_front.damage_multiplier;
    if (rawDamage === 0) return;
  }

  // Проверка weak_point
  if (target.def.weak_point) {
    const inZone = isInWeakPoint(target, source);
    if (inZone) {
      rawDamage *= target.def.weak_point.damage_multiplier;
    } else if (target.def.weak_point.only_weak_point_vulnerable) {
      return;
    }
  }

  // Броня
  const finalDamage = Math.max(1, rawDamage - (target.def.armor || 0));

  // Применение
  target.hp -= finalDamage;
  if (effects.stun_ms) target.stunned_until = performance.now() + effects.stun_ms * (1 - (target.def.stun_resistance || 0));
  if (effects.knockback) target.knockback_v = effects.knockback * (1 - (target.def.knockback_resistance || 0));

  playSound('hit');

  if (target.hp <= 0) {
    killEntity(target);
  }
}

function killEntity(target) {
  playSound('death');
  if (target.def.drop_emoji) {
    spawnPickup(target.def.drop_emoji, target.x, target.y);
  }
  if (target.def.explosion_on_death) {
    const e = target.def.explosion_on_death;
    if (e.damages_player) applyAreaDamage(target.x, target.y, e.radius, e.damage, world.player);
    if (e.damages_mobs) for (const m of world.mobs) applyDamage(m, e.damage, target, {});
  }
  world.player.score += target.def.score_value ?? world.config.scoring.on_enemy_kill;
  target.dead = true;
}
```

### 7.3. Триггеры

```js
updateTriggers() {
  const now = performance.now();
  for (const trig of this.level.params.triggers || []) {
    if (!trig.enabled) continue;
    if (trig.once && this.triggerState.get(trig.id) === 'done') continue;
    if (this._evaluateCondition(trig.when, now)) {
      for (const action of trig.then) {
        this._executeAction(action);
      }
      if (trig.once) this.triggerState.set(trig.id, 'done');
    }
  }
}

_evaluateCondition(cond, now) {
  switch (cond.type) {
    case 'kill_count': {
      const count = this.world.killCount.get(cond.target_emoji) || 0;
      return compare(count, cond.count, cond.compare);
    }
    case 'kill_all': {
      const alive = this.world.mobs.some(m => !cond.target_emoji || m.emoji === cond.target_emoji);
      return !alive;
    }
    case 'pickup_count': {
      const count = this.world.pickupCount.get(cond.pickup_id) || 0;
      return compare(count, cond.count, cond.compare);
    }
    case 'player_hp':
      return compare(this.world.player.hp, cond.value, cond.compare);
    case 'time_elapsed':
      return (now - this.levelStartTime) / 1000 >= cond.seconds;
    case 'player_in_zone':
      return isInRect(this.world.player, cond.col, cond.row, cond.width, cond.height);
    case 'flag_set':
      return this.world.flags.get(cond.flag_name) === true;
    case 'all_of':
      return cond.triggers.every(c => this._evaluateCondition(c, now));
    case 'any_of':
      return cond.triggers.some(c => this._evaluateCondition(c, now));
    default:
      return false;
  }
}
```

### 7.4. Условия победы/поражения

```js
checkVictoryDefeat() {
  const now = performance.now();
  const vc = this.level.params.victory_conditions;
  const dc = this.level.params.defeat_conditions;

  // Поражение
  if (dc && dc.length > 0) {
    for (const cond of dc) {
      if (this._evaluateDefeat(cond)) {
        this._emitDefeat({ reason: cond.type });
        return;
      }
    }
  }

  // Победа
  if (vc && vc.length > 0) {
    if (vc.every(c => this._evaluateVictory(c))) {
      this._emitVictory({ reason: 'conditions' });
    }
  }
}
```

Приоритет: если в одном кадре оба — победа.

### 7.5. Анимации

`renderer-core.drawCell` использует `bankMap` и `startTime`. Для скролл-движков `startTime` = время первого появления клетки на экране (`first_visible_at`).

### 7.6. Звук

`audio-core.playSound(event)` — вызывается движком при событиях.

---

## 8. Производительность

### 8.1. Предрендер статики

- Offscreen canvas для статичных тайлов.
- Анимированные emoji не предрендерятся — рисуются в игровом цикле.
- Перерисовка offscreen — только при изменении тайлов (ямы, `set_tile`).

### 8.2. Виртуализация

Для скролл-движков рендерятся только видимые тайлы:

```js
const startCol = Math.max(0, Math.floor(this.cameraX));
const endCol = Math.min(this.world.cols, Math.ceil(this.cameraX + 32));
// ...
```

### 8.3. Fixed timestep

`update(dt)` вызывается с фиксированным `dt`. Рендер — каждый `requestAnimationFrame`.

### 8.4. Ограничение на количество сущностей

- Мобы: ≤ 100 одновременно (для защиты от DoS через `spawn`).
- Снаряды: ≤ 100 одновременно.

### 8.5. Кэширование

- `bankMap` предзагружается до старта.
- Изображения анимаций загружаются один раз.

---

## 9. Тестирование

### 9.1. Модульные тесты

- `level-parser.test.js` — парсинг сетки, композиты, ошибки.
- `ai-core.test.js` — 50 контроллеров, fallback для неподдерживаемых.
- `patterns.test.js` — 50 паттернов, формулы.
- `actions.test.js` — 14 действий.
- `validators.test.js` — структурная и семантическая валидация.

### 9.2. Интеграционные тесты

- Каждый движок: запуск уровня, проверка проходимости базовых сценариев.
- Боевая система: урон, смерть, i-frames.
- Триггеры: срабатывание, `once`.

### 9.3. Сквозные тесты

- `/play/...` — SSR, bootstrap, загрузка движка.
- Победа/поражение — overlay, переход на следующий уровень.

### 9.4. Ручное тестирование

- Демо-игры (см. критерии).

---

## 10. Критерии приёмки блока 11

### 10.1. Архитектурные

1. **Три движка зарегистрированы** в `registry.js`; `/play/...` работает с любым.
2. **Общие модули** (`audio-core`, `input-core`, `renderer-core`, `legend-resolver`, `level-parser`, `ai-core`, `patterns`, `actions`, `validators`, `engine-base`) используются всеми движками.
3. **`EngineBase`** — единый контракт; `init/start/stop/pause/resume/destroy` реализованы во всех.
4. **Fixed timestep** — `update()` с фиксированным `dt`; `render()` — каждый rAF.
5. **`speed_scale`** применяется ко всем скоростным константам.
6. **`sprite_flicker`** работает для движущихся спрайтов.
7. **`input_responsiveness`** буферизует ввод с задержкой.
8. **`animation_speed_scale`** влияет на длительность кадров анимаций.
9. **`fps`** ∈ {30, 60} — шаг физики.
10. **`show_hud`** — HUD скрывается/показывается.

### 10.2. `static-screen`

11. **Lode Runner-подобная игра** проходима: игрок появляется, ходит, лазает, копает, собирает золото, доходит до выхода.
12. **Cauldron/Robin-подобная** (`top_down_8dir`): 8-направленное движение, стрельба, ключи, двери.
13. **Bomberman-подобная** (`top_down_4dir_no_diag`): строгие 4 направления, бомбы.
14. **Композиты-мобы** — bounding box, движение композита.
15. **Применение `engine_config`**: `movement_type`, `physics.*`, `hole_duration_ms`, `trap_duration_ms`.
16. **Копание** — ямы, закрытие, смерть в закрывающейся яме.
17. **AI** — все модели из `supported_engines: static-screen` работают.
18. **Паттерны** — все из `supported_engines: static-screen`.
19. **Действия** — все 14 типов работают (при соответствующих условиях).

### 10.3. `vertical-scroll`

20. **River Raid-подобная** проходима: скролл, топливо, заправки, паттерны.
21. **Galaga-подобная**: формации, `spiral_in`, `figure8`.
22. **Процедурная генерация** — детерминирована по `seed`.
23. **Сегменты** — переход по `bridge`.
24. **Применение `engine_config`**: `scroll_speed`, `physics.*`, `fuel_capacity`.
25. **Формации** — `leader` + `follower`.

### 10.4. `horizontal-scroll`

26. **Mario-подобная** проходима: прыжки, `variable_jump`, `coyote_time`, `jump_buffer`, чекпоинты, трубы.
27. **Myth-подобная**: HP, ближний бой, боссы.
28. **`on_stomp`** — прыжок на моба, `morph_to`.
29. **Камера** — `follow`, `room`, `fixed`.
30. **Параллакс** — задний слой.
31. **Применение `engine_config`**: `camera_mode`, `physics.*`.
32. **Применение `engine_overrides`**: `speed_scale`, `sprite_flicker`.

### 10.5. Общие системы

33. **Композиты** — парсинг, коллизии, AI, рендер.
34. **Боевая система** — урон, HP, i-frames, knockback, stun, death flow.
35. **Триггеры** — все 13 типов условий, все 15 типов действий.
36. **Условия победы/поражения** — работают, приоритет победы.
37. **Анимации** — играют, `loop_mode` работает.
38. **Звук** — играет по событиям, громкость = `min(volume, sound_volume)`.

### 10.6. Производительность и стабильность

39. **60 FPS** на типичном уровне (32×24 / 32×200 / 200×24).
40. **Виртуализация** — рендер только видимых тайлов.
41. **Предрендер статики** — offscreen canvas.
42. **Лимиты** — мобы ≤ 100, снаряды ≤ 100.

### 10.7. Безопасность

43. **Нет `eval`**, нет `new Function`, нет `innerHTML` для пользовательских данных.
44. **`show_message`** — `textContent`, не `innerHTML`.
45. **Валидация перед запуском** — все проверки `validators.js`.

### 10.8. Тестирование

46. **50+ тестов** для `level-parser`, `ai-core`, `patterns`, `actions`, `validators` — все проходят.
47. **Сквозные тесты** `/play/...` — проходят.

### 10.9. Обратная совместимость

48. **Критерии блоков 1–10** выполняются.
49. **Демо-игры** из блоков 7–8 запускаются и проходимы.

---

## 11. Оценка

**Ориентир:** 8–10 человеко-дней на разработчика (или ~3 рабочих дня с ИИ-ассистентом).

Основное время:

- **`shared/` модули** (`legend-resolver`, `level-parser`, `renderer-core`, `audio-core`, `input-core`, `validators`, `engine-base`) — 1,5–2 дня.
- **`ai-core.js`** — 50 контроллеров — 2 дня.
- **`patterns.js`** — 50 паттернов — 1,5 дня.
- **`actions.js`** — 14 действий — 0,5 дня.
- **`static-screen`** — физика (gravity / top_down), копание, лестницы, композиты — 1,5 дня.
- **`vertical-scroll`** — скролл, процедурная генерация, формации — 1 день.
- **`horizontal-scroll`** — камера, прыжки (variable/coyote/buffer/double), чекпоинты, трубы — 1,5 дня.
- **Боевая система** — урон, HP, i-frames, death flow — 0,5 дня.
- **Триггеры** — 13 условий + 15 действий — 0,5 дня.
- **Производительность** — предрендер, виртуализация — 0,3 дня.
- **Тестирование и отладка** — 0,7 дня.

---

## 12. Что не делаем в блоке 11 и почему

- **Не реализуем новые AI-модели/паттерны/действия** — библиотеки фиксированы (блоки 5, 7, 10). Расширение — через кастомные в админке (блоки 3, 5).
- **Не реализуем 3D, изометрию** — только 2D-сетка 32×24.
- **Не реализуем мультиплеер** — только одиночная игра.
- **Не реализуем облачные сохранения** — вне объёма.
- **Не реализуем мобильное управление** — только клавиатура (см. блок 3, `KeyboardEvent.code`).
- **Не реализуем полноценный pathfinding с динамическими препятствиями** — только `direct`, `greedy`, `astar` по статичной сетке (в AI-моделях).
- **Не реализуем запись «эталонного прохождения»** — блок 13 (опционально).
- **Не реализуем редактор формул** — только параметрические паттерны.
- **Не реализуем WebGL / шейдеры** — только Canvas 2D.
- **Не реализуем продвинутую физику** (ragdoll, fluid) — только базовая grid-based.

---

**Конец блока 11 из 13.**

Следующий блок — **Публичная часть и бэкапы** (каталог, страницы жанров/категорий, карточка игры, `/emoji`, SEO, `sitemap.xml`, `robots.txt`, rate-limiting, массовые операции, бэкапы).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 12. Публичная часть и бэкапы

## 1. Общее описание

Публичная часть — **отдельный слой CMS**, доступный без авторизации. Показывает опубликованные игры (`status: published`), позволяет запускать их через `/play/...`, а также отображает витрину банка эмодзи. Администратор управляет каталогом, жанрами, категориями, карточками игр и уровнями из админки (блоки 1–11); публичная часть читает те же JSON-файлы.

**Ключевые принципы:**

- **SSR (server-rendered).** Публичные страницы рендерятся на сервере; JS — только для игрового движка (`/play/...`) и интерактивных элементов UI.
- **Только `published`.** `draft`-игры не видны, не индексируются, 404 по прямому URL.
- **SEO.** Meta-теги, Open Graph, Twitter Card, canonical, `sitemap.xml`, `robots.txt`.
- **Rate-limiting.** Публичные эндпоинты защищены от злоупотреблений (см. блок 3, §6).
- **Аналитика (опционально).** Счётчики просмотров и запусков — при `config.analytics_enabled = true`.
- **Бэкапы.** Автоматические и ручные; восстановление; drill.

**Место в архитектуре:** публичная часть использует те же JSON-файлы `data/` и медиа `public/media/`, что и админка. Никаких отдельных БД или кэшей (кроме HTTP-кэша для статики).

---

## 2. Публичные маршруты

| URL | Контроллер | Что отдаёт |
|---|---|---|
| `/` | `public/home.php` | Лендинг: свежие игры, популярные жанры, кнопка «Каталог» |
| `/games` | `public/games.php` | Каталог с фильтрами, поиском, сортировкой, пагинацией |
| `/games/{slug}` | `public/game.php` | Карточка игры |
| `/genres` | `public/genres.php` | Список жанров |
| `/genres/{slug}` | `public/genre.php` | Игры жанра |
| `/categories` | `public/categories.php` | Список категорий (дерево) |
| `/categories/{slug}` | `public/category.php` | Игры категории и потомков |
| `/emoji` | `public/emoji.php` | Витрина банка эмодзи |
| `/play/{slug}` | `public/play.php` | Запуск игры с первого уровня |
| `/play/{slug}/{level-id}` | `public/play.php` | Запуск с конкретного уровня |
| `/api/emoji-bank.json` | `public/api/emoji-bank.php` | Публичный эндпоинт банка (блок 6, §7) |
| `/sitemap.xml` | `public/sitemap.php` | Карта сайта |
| `/robots.txt` | `public/robots.php` | Правила для роботов |
| `404`, `500` | `public/error.php` | Страницы ошибок |

**Правила:**

- Все URL — человекочитаемые, со `slug`.
- HTTP 200 для существующих страниц; 404 для несуществующих/`draft`-игр.
- Canonical URL: **без trailing slash**, кроме корня (`/`).
- Язык — только русский (`lang="ru"`).

---

## 3. Каталог `/games`

### 3.1. Layout

```
┌─ Шапка: логотип | Каталог | Жанры | Категории | Банк | ──┐
├──────────────────────────────────────────────────────────┤
│  Каталог игр                                              │
│                                                           │
│  ┌─ Фильтры ──────────────┐  ┌─ Результаты ─────────────┐│
│  │ Жанр:                  │  │ [карточка] [карточка]    ││
│  │  ● Все                 │  │ [карточка] [карточка]    ││
│  │  ○ Lode Runner         │  │                          ││
│  │  ○ Arcade adventure    │  │ Показано 24 из 87        ││
│  │  ○ Платформеры         │  │ ← 1 2 3 4 →              ││
│  │  ○ Myth-like           │  │                          ││
│  │  ○ Вертикальные шутеры │  │                          ││
│  │                        │  │                          ││
│  │ Категория:             │  │                          ││
│  │  ☐ Аркады              │  │                          ││
│  │  ☐ Головоломки         │  │                          ││
│  │  ☐ Шутеры              │  │                          ││
│  │  ☐ Приключения         │  │                          ││
│  │                        │  │                          ││
│  │ Сортировка:            │  │                          ││
│  │  [Сначала новые ▼]     │  │                          ││
│  └────────────────────────┘  └──────────────────────────┘│
│                                                           │
│  Поиск: [_________________________________]              │
└──────────────────────────────────────────────────────────┘
```

### 3.2. Карточка игры в каталоге

```
┌──────────────────┐
│   [обложка 4:3]  │
│   ▶ hover        │
├──────────────────┤
│ Золото           │
│ Бунгелингов      │
│ Lode Runner      │
│ 5 уровней        │
└──────────────────┘
```

**Содержимое:**

- Обложка `cover.webp` (640×480), при hover — кнопка «▶ Играть».
- Название.
- Жанр.
- Количество уровней (`levels.length`).

**Клик по карточке** → `/games/{slug}`.

### 3.3. Фильтры, сортировка, поиск, пагинация

**Все фильтры — GET-параметры** (bookmarkable URL):

```
/games?genre=lode-runner&category[]=puzzle&category[]=arcade&q=золото&sort=newest&page=2
```

| Параметр | Тип | Поведение |
|---|---|---|
| `genre` | string | Один жанр (slug) |
| `category[]` | array | Несколько категорий (slug); включают потомков |
| `q` | string | Поиск по `title`, `slug`, `description`; минимум 2 символа |
| `sort` | enum | `newest` (default) \| `oldest` \| `title` \| `levels_count` |
| `page` | int | ≥ 1; 24 игры на страницу |

**Правила:**

- Неизвестные значения фильтров — игнорируются.
- Фильтр по категории-родителю включает все дочерние категории (рекурсивно).
- Поиск — `LIKE` (регистронезависимо, `mb_strtolower`), без морфологии.
- Пустой результат: «Ничего не найдено. Попробуйте изменить фильтры.»
- Состояние фильтров сохраняется в URL; при возврате — восстанавливается.
- Только `status: published`.

### 3.4. Пагинация

- 24 игры на страницу.
- `?page=N`.
- Ссылки «←», «→», номера страниц; активная подсвечена.
- При `page > max` → 404.
- `rel="prev"` / `rel="next"` в `<head>`.

---

## 4. Страницы жанра и категории

### 4.1. `/genres`

Список всех жанров из `data/genres.json` в виде плиток:

```
┌─────────────────────────────────────────────────────────┐
│  Жанры                                                   │
│                                                          │
│  ┌──────────────┐ ┌──────────────┐ ┌──────────────┐    │
│  │ Lode Runner  │ │ Arcade       │ │ Платформеры  │    │
│  │ 12 игр       │ │ adventure    │ │ 35 игр       │    │
│  │              │ │ 8 игр        │ │              │    │
│  └──────────────┘ └──────────────┘ └──────────────┘    │
└─────────────────────────────────────────────────────────┘
```

- Только жанры, у которых есть **хотя бы одна** `published`-игра (иначе — не показывать; но в `sitemap.xml` — тоже только непустые).
- Клик → `/genres/{slug}`.

### 4.2. `/genres/{slug}`

- Шапка: название жанра, описание.
- Сетка карточек игр жанра (только `published`).
- Связанные категории — бейджи.
- Другие жанры — внизу.
- `404`, если жанр не существует.

### 4.3. `/categories`

- Древовидное представление (рекурсивно).
- Только категории, у которых есть **хотя бы одна** `published`-игра.
- Клик → `/categories/{slug}`.

### 4.4. `/categories/{slug}`

- Шапка: название, описание.
- Подкатегории (если есть) — бейджи.
- Сетка карточек игр **категории и всех её потомков** (только `published`).
- `404`, если категория не существует.

---

## 5. Карточка игры `/games/{slug}`

### 5.1. Layout

```
┌─ Хлебные крошки: Каталог / Lode Runner / Золото Бунгелингов ─┐
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  ┌─ Обложка 4:3 ────────┐   Золото Бунгелингов              │
│  │                      │   Lode Runner-подобные              │
│  │      [cover]         │                                    │
│  │                      │   Одноэкранная головоломка:        │
│  │      [▶ Играть]      │   соберите всё золото, выкопайте   │
│  └──────────────────────┘   ямы и уйдите через выход.       │
│                                                              │
│                              Категории: Головоломки, Аркады │
│                              Жанр: Lode Runner-подобные     │
│                                                              │
│  [▶ Играть с первого уровня]   [Случайный уровень]          │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│  Уровни (5)                                                  │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐    │
│  │[thumb] │ │[thumb] │ │[thumb] │ │[thumb] │ │[thumb] │    │
│  │Вход в  │ │Лестница│ │Лагерь  │ │Тронный │ │Побег   │    │
│  │шахту   │ │        │ │шерифа  │ │зал     │ │        │    │
│  │▶ Играть│ │▶ Играть│ │▶ Играть│ │▶ Играть│ │▶ Играть│    │
│  └────────┘ └────────┘ └────────┘ └────────┘ └────────┘    │
│                                                              │
├─────────────────────────────────────────────────────────────┤
│  Похожие игры                                                │
│  ┌────────┐ ┌────────┐ ┌────────┐                           │
│  │[cover] │ │[cover] │ │[cover] │                           │
│  └────────┘ └────────┘ └────────┘                           │
└─────────────────────────────────────────────────────────────┘
```

### 5.2. Обязательные атрибуты (из блока 7)

1. **Название** — `<h1>`.
2. **Жанр** — ссылка на `/genres/{slug}`.
3. **Описание** — plain text, экранированный.
4. **Обложка** — `cover.webp`.
5. **Галерея уровней** — сетка миниатюр `thumb.webp` с названиями; клик → `/play/{slug}/{level_id}`.

**Дополнительно:**

- Категории — бейджи-ссылки.
- Кнопка «Играть» → `/play/{slug}` (первый уровень).
- Кнопка «Случайный уровень» → случайный `level_id`.
- Хлебные крошки.
- Related games — до 3 игр того же жанра, кроме текущей, отсортированные по `updated_at` desc.

### 5.3. Галерея уровней

- Превью `level-XXX-thumb.webp` (320×240).
- Анимированные эмодзи — **первый кадр** (статичное изображение).
- Композиты — видны целиком.
- Hover на миниатюре → «▶ Играть».
- Если `thumb` отсутствует (старая игра) — плейсхолдер 🗺️.

### 5.4. `draft`-игры

- `/games/{slug}` для `draft` — **404** (не 403, чтобы не раскрывать существование).
- Не появляются в каталоге, жанрах, категориях, `sitemap.xml`.

---

## 6. Страница `/emoji`

### 6.1. Layout

```
┌─ Эмодзи-банк CMS ───────────────────────────────────┐
│  Кастомные 8-битные спрайты, доступные в играх.      │
│                                                       │
│  Фильтр: [Все] [Персонажи] [Оружие] [Природа] ...    │
│                                                       │
│  ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐ ┌─────┐            │
│  │[img]│ │[img]│ │[img]│ │[img]│ │[img]│            │
│  │e001 │ │e002 │ │e003 │ │e004 │ │e005 │            │
│  │Рыц. │ │Меч  │ │Щит  │ │Зелье│ │Ключ │            │
│  └─────┘ └─────┘ └─────┘ └─────┘ └─────┘            │
│                                                       │
│  Показано: 50 из 50                                   │
└───────────────────────────────────────────────────────┘
```

### 6.2. Поведение

- Показывает **только статические** эмодзи (без анимаций) — по умолчанию. Анимации — на hover в модалке.
- Фильтр по 6 категориям.
- Клик по эмодзи → модалка с крупным превью, названием, кодпоинтом, «используется в играх: …».
- **Безопасность:** рендер через `<img src="/media/emoji-bank/...">`, никакого инлайн-SVG.

### 6.3. SEO

- `robots: index,follow`.
- Каждый эмодзи не индексируется отдельно (нет отдельной страницы) — только общая страница.
- `meta description`: «Кастомные 8-битные спрайты CMS».

---

## 7. Публичный запуск `/play`

### 7.1. SSR-страница

```
GET /play/{game-slug}            → первый уровень
GET /play/{game-slug}/{level-id} → конкретный уровень
```

**Логика:**

1. По `{game-slug}` найти игру. Если `status !== 'published'` → 404.
2. Если `{level-id}` не передан — первый уровень.
3. Если `{level-id}` не найден в игре — 404.
4. Найти жанр по `genre_id`.
5. Собрать `bootstrap` JSON:

```json
{
  "game": { /* карточка игры без meta, created_at, updated_at */ },
  "genre": { "engine": "...", "engine_config": {...} },
  "level": { /* конкретный уровень */ },
  "effectiveLegend": { /* {...default_legend, ...legend} */ }
}
```

6. Встроить `bootstrap` в HTML через `<script type="application/json" id="bootstrap">`.
7. Подключить `boot.js` (блок 11, §2.2).
8. При ошибке валидации (блок 11, §3.9) — страница ошибки с причиной.

### 7.2. HTML-страница

```html
<!DOCTYPE html>
<html lang="ru">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>{{game.title}} — {{level.name}} — 8-bit CMS</title>
  <meta name="description" content="...">
  <!-- OG, Twitter, canonical -->
  <link rel="stylesheet" href="/assets/public.css">
</head>
<body>
  <div id="game-wrap">
    <canvas id="game" width="{{32 * tile_size}}" height="{{24 * tile_size}}"></canvas>
    <div id="hud"></div>
    <div id="overlay" class="hidden"></div>
  </div>
  <div id="controls-hint">← → ↑ ↓ — движение, Space — действие, Esc — пауза</div>
  <script type="application/json" id="bootstrap">{...}</script>
  <script type="module" src="/assets/engines/boot.js"></script>
</body>
</html>
```

**Размер canvas:** `32 × tile_size` × `24 × tile_size`. При `tile_size: 8` → 256×192 (ZX Spectrum); при 16 → 512×384; CSS масштабирует.

### 7.3. Overlay

- **Победа** — «Уровень пройден» + кнопка «Следующий уровень» (или «Игра пройдена» на последнем).
- **Поражение** — «Игра окончена» + «Начать заново».
- **Пауза** — «Пауза» + «Продолжить».

### 7.4. Индексирование

`/play/*` **не индексируется** (`robots.txt` Disallow). SEO-ценность — у `/games/{slug}`.

---

## 8. SEO

### 8.1. Meta-теги на страницах

| Страница | `<title>` | `meta description` | OG |
|---|---|---|---|
| `/` | «8-bit CMS — 8-битные игры» | Описание CMS | `website`, `og:image=logo` |
| `/games` | «Каталог игр — 8-bit CMS» | «Все игры каталога» | `website` |
| `/games/{slug}` | «{title} — {genre} — 8-bit CMS» | Первые 160 символов `description` | `article`, `og:image=cover` |
| `/genres/{slug}` | «{genre.name} — игры» | `genre.description` | `website` |
| `/categories/{slug}` | «{category.name} — игры» | `category.description` | `website` |
| `/emoji` | «Банк эмодзи — 8-bit CMS» | «Кастомные 8-битные спрайты» | `website` |
| `/play/{slug}` | «{title} — играть» | `description` | `article`, `og:image=cover` |

**SEO-мета** игры (`meta.seo_title`/`meta.seo_description` из блока 7) используются, если заданы; иначе fallback на `title`/`description`.

### 8.2. Open Graph и Twitter Card

```html
<meta property="og:type" content="article">
<meta property="og:title" content="...">
<meta property="og:description" content="...">
<meta property="og:image" content="{{base_url}}/media/{game-id}/cover.webp">
<meta property="og:url" content="{{base_url}}/games/{slug}">
<meta property="og:site_name" content="8-bit CMS">

<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="...">
<meta name="twitter:description" content="...">
<meta name="twitter:image" content="...">
```

### 8.3. Canonical

```html
<link rel="canonical" href="https://example.com/games/{slug}">
```

На всех публичных страницах.

### 8.4. `sitemap.xml`

Генерируется на лету (`public/sitemap.php`):

```xml
<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://example.com/</loc><changefreq>daily</changefreq></url>
  <url><loc>https://example.com/games</loc><changefreq>daily</changefreq></url>
  <url><loc>https://example.com/games/zoloto-bungelingov</loc><lastmod>2026-10-21</lastmod></url>
  <url><loc>https://example.com/genres/lode-runner</loc></url>
  <url><loc>https://example.com/categories/puzzle</loc></url>
  <url><loc>https://example.com/emoji</loc></url>
</urlset>
```

- **Не включает:** `draft`-игры, пустые жанры/категории, `/play/*`, `/api/*`, `/admin/*`.
- `Cache-Control: public, max-age=3600`.

### 8.5. `robots.txt`

```
User-agent: *
Disallow: /admin/
Disallow: /play/
Disallow: /api/
Disallow: /data/
Allow: /

Sitemap: https://example.com/sitemap.xml
```

### 8.6. Страницы 404 и 500

- Стилизованы в духе CMS (монохром, 8-бит).
- 404: «Страница не найдена» + ссылка на каталог.
- 500: «Ошибка сервера» + контакт (если задан в `config.php`).
- `robots: noindex`.

---

## 9. Rate-limiting

Детали реализации — блок 3, §6. Сводка публичных эндпоинтов:

| Эндпоинт | Лимит | Окно |
|---|---|---|
| `GET /play/*` | 60 | 60 сек на IP |
| `GET /api/emoji-bank.json` | 120 | 60 сек на IP |
| `GET /games*`, `/genres*`, `/categories*`, `/emoji` | 120 | 60 сек на IP |
| `GET /` | 120 | 60 сек на IP |

При превышении — HTTP 429 с `Retry-After`.

---

## 10. Массовые операции в админке

### 10.1. Список игр `/admin/games`

Добавляется колонка чекбоксов и панель массовых действий:

```
┌─ Массовые действия ─────────────────────────────┐
│  Выбрано: 5 игр                                 │
│  [Опубликовать]  [В черновик]  [Удалить]        │
└─────────────────────────────────────────────────┘
```

### 10.2. Bulk publish / draft

- Выбор игр чекбоксами.
- POST `/admin/games/bulk` с `action=publish|draft`, `ids[]`.
- **Атомарность:** сначала проверка всех выбранных; при ошибке в любой — операция отклоняется.
- **Проверки при `publish`:**
  - `cover` существует;
  - `levels.length >= 1`;
  - все обязательные действия движка назначены в `controls`;
  - уровни валидны по `level_rules`.
- Игры без обязательных полей — **пропускаются** с сообщением «Пропущено N игр: …».
- Лимит: **50 игр за операцию**.
- `audit.log` → `game.bulk_publish` / `game.bulk_draft`.

### 10.3. Bulk delete

- Модалка с вводом слова «УДАЛИТЬ».
- При подтверждении — удаляются: JSON карточки, `media/{game-id}/` (обложка, превью), запись в `audit.log`.
- **Атомарность:** если удаление хотя бы одной игры невозможно (например, ошибка файловой системы) — откат всех.
- Лимит: 50 игр.
- `audit.log` → `game.bulk_delete` с перечнем.

### 10.4. CSRF

Все массовые операции — POST с CSRF-токеном (блок 3, §3).

---

## 11. Копирование настроек

**Кнопка «Скопировать настройки из…»** — в карточке игры на вкладке «Настройки» (блок 9, §6).

- **Источник** — другая игра **того же движка**.
- **Что копировать** — `controls`, `sounds`, `engine_overrides` (чекбоксы).
- **Не мержится** — перезапись.
- `audit.log` → `game.settings_copy`.

**Копирование боевых настроек** (`player_attacks`, `player.lives`, `scoring`) — как часть `engine_overrides`, если выбран чекбокс «Боевые настройки» (после блока 10).

---

## 12. Аналитика (опционально)

### 12.1. Что собирается

`data/stats.json`:

```json
{
  "game-001": {
    "views": 150,
    "plays": 80,
    "last_view_at": "2026-10-21T20:00:00Z",
    "last_play_at": "2026-10-21T20:05:00Z"
  }
}
```

- **`views++`** — при `GET /games/{slug}`.
- **`plays++`** — при `GET /play/{slug}` и `/play/{slug}/{level-id}`.

### 12.2. Отображение

- **Дашборд админки** (блок 4, §2) — топ-5 по просмотрам и запускам.
- **Карточка игры в админке** — счётчики просмотров и запусков.
- **Каталог** — опциональная сортировка «по популярности» (по `plays`).

### 12.3. Ограничения

- Не собирает IP, User-Agent, cookies — только агрегированные счётчики.
- Атомарная запись через `FileStore` с `flock`.
- **`config.analytics_enabled = false`** — счётчики не пишутся.

---

## 13. Бэкапы

### 13.1. Что бэкапится

| Путь | Приоритет | Частота |
|---|---|---|
| `data/` | **Критично** | Каждые 6 часов + перед массовыми операциями |
| `public/media/` | **Критично** | Каждые 6 часов |
| `config/` (кроме `secrets.php`) | Высокий | При изменении |
| Код (`src/`, `admin/`, `public/*.php`) | Высокий | В git |
| `config/secrets.php` | **Критично** | Отдельно, не в git |

### 13.2. `cli/backup.php`

```bash
php cli/backup.php [--out=/path] [--keep=14] [--tag=manual|scheduled]
```

**Логика:**

1. Валидация всех JSON в `data/` (парсинг, схема). При ошибке — бэкап не создаётся, exit code 1.
2. Создание `backup-YYYYMMDD-HHMMSS-{tag}.tar.gz`:
   - `data/`;
   - `public/media/`;
   - `config/` (кроме `secrets.php`);
   - `VERSION`.
3. Расчёт `sha256` архива; сохранение рядом `backup-....tar.gz.sha256`.
4. Ротация: удаление архивов старше `--keep` (по умолчанию 14).
5. Запись в `data/backup.log`:

```json
{"ts":"...","file":"backup-....tar.gz","bytes":123456,"sha256":"...","tag":"scheduled"}
```

**Требования:**

- Атомарность: запись во временный файл → rename.
- `flock` на каталог бэкапов — защита от параллельных запусков.

### 13.3. `cli/restore.php`

```bash
php cli/restore.php backup-20261021-220000-scheduled.tar.gz --confirm
```

**Логика:**

1. Проверка `sha256` архива.
2. Распаковка во временный каталог.
3. Валидация структуры и JSON.
4. **Бэкап текущего состояния** → `backup-pre-restore-....tar.gz` (страховка).
5. Замена `data/` и `public/media/`.
6. `audit.log` → `restore.completed`.

**Без `--confirm`** — dry-run (проверка без изменений).

### 13.4. Cron-пример (README)

```cron
0 */6 * * * cd /var/www/cms && php cli/backup.php --keep=14 --tag=scheduled >> /var/log/cms-backup.log 2>&1
0 3 * * 0   cd /var/www/cms && php cli/backup.php --keep=14 --tag=weekly >> /var/log/cms-backup.log 2>&1
```

**Внешнее хранилище** (рекомендация в README): синхронизация каталога бэкапов с S3/Backblaze/Yandex Object Storage через `rclone`/`aws-cli`.

### 13.5. Drill восстановления

**Обязателен перед приёмкой:**

1. Полный бэкап.
2. Удаление случайного файла в `data/` и `public/media/`.
3. Восстановление из бэкапа.
4. Проверка работоспособности (вход, каталог, игра).
5. Запись в `tests/backup-drill.md` — дата, результат, время восстановления.

### 13.6. Бэкапы перед массовыми операциями

Перед bulk delete, bulk publish, сменой `engine` жанра — **автоматический бэкап** `data/` (без `public/media/`, для скорости) в `data/backups/pre-op-YYYYMMDD-HHMMSS.tar.gz`. Хранится 7 дней.

---

## 14. Валидация

### 14.1. Публичные страницы

| Проверка | Действие |
|---|---|
| `{slug}` соответствует `^[a-z0-9][a-z0-9-]{0,78}[a-z0-9]$` | 404 при несоответствии |
| Игра существует и `status === 'published'` | 404 |
| Жанр/категория существуют | 404 |
| `{level-id}` соответствует `^level-\d{3,}$` и существует в игре | 404 |
| `page` ≥ 1 и ≤ max | 404 при превышении |
| `q` ≥ 2 символов | Игнорируется, если меньше |

### 14.2. Массовые операции

| Проверка | Действие |
|---|---|
| Выбрано 1–50 игр | Ошибка при 0 или >50 |
| Все игры существуют | Ошибка при отсутствии |
| При `publish` — все обязательные поля | Пропуск с сообщением |
| CSRF-токен | 403 при невалидном |

### 14.3. Бэкапы

| Проверка | Действие |
|---|---|
| JSON валиден | Отказ от бэкапа |
| `sha256` архива при restore | Отказ при несовпадении |
| Структура архива | Отказ при несоответствии |

---

## 15. Безопасность

| Угроза | Мера |
|---|---|
| XSS через `title`/`description`/`genre.name` | `htmlspecialchars()` при выводе |
| XSS через `slug` в URL | Regex-валидация, поиск в массиве |
| Path traversal через `{slug}` | Поиск в данных; при отсутствии — 404 |
| Открытие `draft`-игры по прямому URL | 404 |
| Инъекция через фильтры (`?genre=...`) | Whitelist/enum; неизвестное — игнорируется |
| DoS через частые запросы | Rate-limiting (блок 3, §6) |
| Bot-scraping | `robots.txt` + `Retry-After` |
| Утечка `meta.seo_*` для `draft` | Не выводятся |
| XSS через встроенный `bootstrap` JSON | `JSON_HEX_TAG \| JSON_HEX_AMP \| JSON_HEX_APOS \| JSON_HEX_QUOT` при `json_encode` |
| CSRF на массовых операциях | Токен из блока 3 |
| Случайное массовое удаление | Модалка с вводом «УДАЛИТЬ» |
| Утечка `/data/` | Запрет (блок 3, §4) |
| Утечка `config/secrets.php` | Запрет + `0600` |

---

## 16. Аудит

```json
{"ts":"...","user":"admin","action":"game.bulk_publish","details":{"count":5,"ids":["game-001","game-002","game-003","game-004","game-005"],"skipped":[]}}
{"ts":"...","user":"admin","action":"game.bulk_delete","details":{"count":3,"ids":["game-010","game-011","game-012"]}}
{"ts":"...","user":"admin","action":"game.settings_copy","target":"game-005","details":{"source":"game-001","fields":["controls","sounds","engine_overrides"]}}
{"ts":"...","user":null,"action":"public.rate_limit","details":{"ip":"1.2.3.4","endpoint":"/play/game-001","count":62}}
{"ts":"...","user":null,"action":"public.404","details":{"url":"/games/nonexistent","ip":"1.2.3.4"}}
{"ts":"...","user":"system","action":"backup.create","details":{"file":"backup-....tar.gz","bytes":123456,"tag":"scheduled"}}
{"ts":"...","user":"admin","action":"backup.restore","details":{"file":"backup-....tar.gz","duration_ms":4500}}
{"ts":"...","user":"system","action":"backup.pre_op","details":{"reason":"bulk_delete"}}
```

`public.*` события — опциональны, включаются флагом `config.audit_public_events` (по умолчанию `false`).

---

## 17. Критерии приёмки блока 12

### 17.1. Публичный каталог

1. **`/`** — лендинг открывается; показывает свежие `published`-игры и популярные жанры.
2. **`/games`** — открывается; только `published`-игры; сетка карточек.
3. **Фильтры.** Жанр (radio), категории (checkbox с потомками), сортировка (`newest`/`oldest`/`title`/`levels_count`), поиск (`title`/`slug`/`description`, ≥2 символов).
4. **URL-состояние.** Параметры сохраняются в URL; восстанавливаются при возврате. Пагинация `?page=N`.
5. **Пустой результат.** «Ничего не найдено».
6. **`/genres`** — все непустые жанры.
7. **`/genres/{slug}`** — игры жанра; breadcrumbs; связанные категории; 404 при отсутствии.
8. **`/categories`** — дерево непустых категорий.
9. **`/categories/{slug}`** — игры категории и потомков; 404 при отсутствии.
10. **`draft`-игры** — не видны в каталоге, 404 по прямому URL.

### 17.2. Карточка игры

11. **`/games/{slug}`** — обложка, название, жанр (ссылка), описание, категории, галерея уровней, кнопка «Играть», related games, breadcrumbs.
12. **Галерея.** Клик по миниатюре → `/play/{slug}/{level-id}`. Анимированные эмодзи и композиты на превью отображаются корректно.
13. **Related games** — до 3 игр того же жанра.
14. **`draft`-игра** → 404.

### 17.3. Страница `/emoji`

15. **Открывается**, показывает статические эмодзи по категориям.
16. **Клик** — модалка с крупным превью, названием, кодпоинтом, «используется в играх».
17. **Анимации** — на hover проигрываются.
18. **Безопасность** — `<img>`, никакого инлайн-SVG.

### 17.4. Публичный запуск

19. **`/play/{slug}`** — запускает первый уровень.
20. **`/play/{slug}/{level-id}`** — запускает конкретный уровень.
21. **`draft`-игра** — 404.
22. **Несуществующий уровень** — 404.
23. **Bootstrap JSON** встроен через `<script type="application/json">`.
24. **Ошибка валидации** — страница «Уровень недоступен: <причина>».
25. **Overlay** — победа, поражение, пауза.

### 17.5. SEO

26. **`<title>`** и `meta description` по шаблонам §8.1.
27. **Open Graph** и **Twitter Card** — присутствуют.
28. **`canonical`** — корректен.
29. **`/sitemap.xml`** — все `published`-игры, непустые жанры и категории, `/emoji`; без `draft`, `/play/*`, `/admin/*`.
30. **`/robots.txt`** — закрывает `/admin/`, `/play/`, `/api/`, `/data/`; указывает `Sitemap`.
31. **`404`** и **`500`** — стилизованные страницы, `noindex`.

### 17.6. Rate-limiting

32. **61-й запрос** на `/play/{slug}` за 60 сек → 429 с `Retry-After`.
33. **121-й запрос** на `/api/emoji-bank.json` → 429.

### 17.7. Массовые операции

34. **Bulk publish.** 5 игр → все становятся `published`. Игры без обязательных полей — пропускаются с сообщением.
35. **Bulk draft.** 3 игры → все `draft`.
36. **Bulk delete.** Модалка «УДАЛИТЬ» → удалены файлы, JSON, `media/`.
37. **CSRF.** POST без токена — 403.
38. **Лимит.** 51 игра — ошибка.
39. **Атомарность.** При ошибке в одной игре — ничего не изменяется.

### 17.8. Копирование настроек

40. **«Скопировать настройки из…»** — другой игры того же движка.
41. **Копируются** `controls`, `sounds`, `engine_overrides`.
42. **Копирование из другого движка** — ошибка.

### 17.9. Аналитика (если включена)

43. **Просмотр `/games/{slug}`** → `views++`.
44. **Запуск `/play/{slug}`** → `plays++`.
45. **Дашборд** — топ-5 по просмотрам и запускам.
46. **Отключение** флагом `analytics_enabled = false` — счётчики не пишутся.

### 17.10. Бэкапы

47. **`cli/backup.php`** создаёт архив с `data/`, `media/`, `config/`, `VERSION`.
48. **Валидация JSON** перед бэкапом.
49. **`sha256`** сохраняется рядом.
50. **Ротация** по `--keep`.
51. **`cli/restore.php`** восстанавливает с проверкой `sha256` и бэкапом текущего состояния.
52. **Drill** выполнен успешно; запись в `tests/backup-drill.md`.
53. **Cron-пример** в README.

### 17.11. Безопасность и аудит

54. **XSS в `title`/`description`** — экранировано.
55. **CSRF** — 403.
56. **Прямой доступ к `/data/*`** — 403/404.
57. **`draft`-игра** — 404.
58. **Аудит** — все массовые операции и бэкапы записаны.

### 17.12. Обратная совместимость

59. **Критерии блоков 1–11** выполняются.
60. **Демо-игры** из блоков 7–8 доступны и проходимы через публичную часть.

---

## 18. Оценка

**Ориентир:** 3–4 человеко-дня на разработчика (или ~1 рабочий день с ИИ-ассистентом).

Основное время:

- **Публичные страницы** — layout, шаблоны, фильтры, пагинация (≈1 день).
- **SEO** — meta, OG, `sitemap.xml`, `robots.txt`, `canonical` (≈0,5 дня).
- **Страница банка эмодзи** — витрина с фильтрами (≈0,3 дня).
- **Массовые операции** — UI, модалки, атомарность (≈0,5 дня).
- **Копирование настроек** — выбор источника, валидация (≈0,2 дня).
- **Rate-limiting** — сводка, проверка (реализация в блоке 3) (≈0,1 дня).
- **Аналитика** — счётчики, дашборд (≈0,3 дня).
- **Бэкапы** — скрипты, drill, cron (≈0,5 дня).
- **Отладка и тестирование** (≈0,5 дня).

---

## 19. Что не делаем в блоке 12 и почему

- **Не реализуем аккаунты игроков, логин, рекорды** — вне объёма.
- **Не реализуем комментарии, рейтинги** — вне объёма.
- **Не реализуем CDN, кэширование страниц на сервере** — базовый `Cache-Control` для статики достаточно.
- **Не реализуем мультиязычность** — только русский.
- **Не реализуем платную монетизацию, рекламу** — вне объёма.
- **Не реализуем RSS/Atom** — опционально в будущем.
- **Не реализуем полнотекстовый поиск с морфологией** — только `LIKE`.
- **Не реализуем копирование `behavior_mode`/`ai_model` между мобами** — вне объёма (только копирование настроек на уровне игры).
- **Не реализуем полноценную антибот-защиту** (CAPTCHA, Hashcash) — rate-limiting достаточно для MVP.
- **Не реализуем бэкап кода** — код в git; бэкапятся только данные и медиа.
- **Не реализуем автоматический откат** после ошибки массовой операции — только атомарность (всё или ничего) + бэкап перед операцией.

---

**Конец блока 12 из 13.**

Следующий блок — **Тестирование, документация, критерии приёмки** (автоматические тесты, сценарный чек-лист, «золотой путь», `cli/validate-levels.php`, README, USER-GUIDE, DEV-GUIDE, SECURITY, CHANGELOG, ADR, PROJECT-REPORT, финальные критерии приёмки проекта).

# ТЗ: Mini CMS с конструктором 8-битных игр (flat-file)

**Версия: 1.0.0**

---

# Блок 13. Тестирование, документация, критерии приёмки

## 1. Общее описание

Заключительный блок — **стабилизация проекта**. Определяет:

- **автоматические тесты** ключевых модулей;
- **сценарный чек-лист** ручной приёмки по всем блокам 1–12;
- **«золотой путь»** — создание игры с нуля по USER-GUIDE без подсказок;
- **headless-проверку проходимости** уровней (опционально, но включено);
- **документацию** (`README`, `USER-GUIDE`, `DEV-GUIDE`, `SECURITY`, `CHANGELOG`, `docs/adr/`, `docs/PROJECT-REPORT.md`);
- **финальные критерии приёмки проекта** — свод всех критериев из блоков 1–12 + нефункциональные требования;
- **передаточный пакет** — что входит в релиз.

**Принцип:** никаких новых фич. Только стабилизация, документирование и приёмка. Единственное исключение — **исправление найденных дефектов** и **уязвимостей**, выявленных при аудите (записываются в `CHANGELOG.md` → `Fixed`).

---

## 2. Автоматические тесты

### 2.1. Структура `tests/`

```
tests/
├── Storage/
│   ├── FileStoreTest.php             # read/write/delete, атомарность
│   ├── AtomicWriteTest.php           # 1000 параллельных записей
│   └── PathValidationTest.php        # path traversal
├── Auth/
│   ├── AuthenticatorTest.php         # login/logout, session_regenerate
│   ├── CsrfTest.php                  # генерация, проверка
│   └── ThrottleTest.php              # 5 неудач → 429
├── Svg/
│   ├── GeometrySanitizerTest.php     # 20+ кейсов
│   ├── GradientValidationTest.php    # url(#id) → существующий градиент
│   ├── XxePreventionTest.php         # DOCTYPE/ENTITY
│   └── ForbiddenElementsTest.php     # script, text, image, use, foreignObject
├── Emoji/
│   ├── PngProcessingTest.php         # MIME, размеры, ре-энкод без изменения размеров
│   ├── SvgProcessingTest.php         # sanitized_hash, отсутствие растеризации
│   ├── PuaCounterTest.php            # не переиспользуется после удаления
│   ├── AnimationTest.php             # кадры, длительности, запрет вложенности
│   └── ZipImportTest.php             # zip-slip, конфликты
├── Validation/
│   ├── GenreValidatorTest.php
│   ├── CategoryValidatorTest.php     # циклы, глубина
│   ├── GameValidatorTest.php         # обязательные поля
│   ├── LegendValidatorTest.php       # роли, композиты, ссылки
│   ├── LevelValidatorTest.php        # размеры, композиты, level_rules
│   ├── SettingsValidatorTest.php     # controls, sounds, engine_overrides
│   └── CombatValidatorTest.php       # player_attacks, player, scoring, triggers
├── Engines/
│   ├── GridParserTest.php            # композиты, якоря, body-спрайты
│   ├── AiRegistryTest.php            # 50 контроллеров, фильтр по движку
│   ├── PatternsTest.php              # 50 паттернов, формулы, краевые случаи
│   ├── ActionsTest.php               # 14 типов действий
│   └── DamageModelTest.php           # формулы урона, weak_point, shield_front
├── Triggers/
│   ├── ConditionTest.php             # 13 типов условий
│   ├── ActionTest.php                # 15 типов действий
│   └── PriorityTest.php              # victory > defeat в одном кадре
└── Public/
    ├── RouterTest.php                # 404, slug, draft
    ├── SitemapTest.php               # только published
    ├── RateLimitTest.php             # 429 с Retry-After
    └── SeoTest.php                   # meta, OG, canonical
```

### 2.2. Требования

| Требование | Значение |
|---|---|
| Фреймворк | PHPUnit 10+ (или Pest) — опционально; допустим самописный раннер `tests/run.php` |
| Количество тестов | **≥ 80** |
| Покрытие ключевых модулей | 100% функций `Storage`, `Svg`, `Validation`, `level-parser`, `ai-core`, `patterns`, `actions` |
| Время прогона | **≤ 60 секунд** на чистом развёртывании |
| Запуск | `php tests/run.php` или `./vendor/bin/phpunit` |
| CI | Пример `.github/workflows/tests.yml` — опционально |

### 2.3. Обязательные тестовые сценарии

**Storage:**

- `read` несуществующего файла → `$default`.
- `read` битого JSON → `StorageException`.
- `write` атомарен: 1000 записей из 10 параллельных процессов — файл не повреждён.
- `mutate` с исключением в мутаторе — файл не изменён.
- Path traversal (`../../etc/passwd`) → `StorageException`.

**Auth:**

- `login` с неверным паролем → false; счётчик throttle инкрементируется.
- 5 неудач с одного IP → 429 с `Retry-After`.
- `session_regenerate_id` при успешном login.
- CSRF: POST без токена → 403.
- Session timeout 60 минут → редирект на login.

**Svg:**

- SVG с `<script>` → элемент удалён.
- SVG с `<text>` → элемент удалён.
- SVG с `<!DOCTYPE` → отказ.
- SVG с `url(#missing)` в `fill` → `fill` → `none`.
- SVG без фигур после санитизации → отказ.
- SVG с `viewBox` 1024×1024 → отказ.
- SVG с `<image href="http://evil.com/x">` → элемент удалён.
- SVG с `onload` → атрибут удалён.

**Emoji:**

- PNG 32×32 → сохраняется как 32×32 (без изменения размеров), `source_size = {32, 32}`.
- PNG 300×300 → отказ (>256).
- GIF → первый кадр, PNG, без изменения размеров.
- SVG → `sanitized_hash` заполнен, файл `.svg`.
- PUA-кодпоинты: после удаления e003 следующий получает U+E003 (не переиспользуется).
- Анимация с 1 кадром → отказ.
- Анимация с суммой > 10000 мс → отказ.
- Анимация с кадром-анимацией → отказ «Кадр должен быть статичным эмодзи».
- ZIP с `../evil.php` → отказ (zip-slip).

**Validation:**

- Жанр: slug неуникален → ошибка.
- Категория: цикл A→B→A → ошибка.
- Категория: глубина 6 → ошибка.
- Игра: отсутствует `cover` при `publish` → ошибка.
- Легенда: два `start` → ошибка.
- Легенда: `pickup_id` неуникален → ошибка.
- Легенда: `shoot.projectile` ссылается на несуществующий → ошибка.
- Уровень: сетка 10×5 для `static-screen` → ошибка.
- Уровень: emoji вне легенды → ошибка.
- Уровень: два композита перекрываются → ошибка.
- Settings: `controls` без обязательного действия → ошибка.
- Settings: `sounds` > 10 → ошибка.
- Settings: `frequency_hz` = 5000 → ошибка.
- Combat: `ranged.enabled` без `projectile_emoji` → ошибка.
- Combat: `stomp.enabled` в `vertical-scroll` → ошибка.

**Engines:**

- `parseGrid`: композит 2×2 — 1 anchor, 4 occupied cells.
- `parseGrid`: композит выходит за границу → ошибка.
- `parseGrid`: body-спрайт вне композита → ошибка (если не самостоятельный 1×1).
- `AI_REGISTRY`: 50 моделей присутствуют.
- `AI_REGISTRY`: `chase_with_vision` фильтрует по `supported_engines`.
- `PATTERNS`: 50 паттернов; `sine_horizontal` возвращает корректную позицию при t=0.
- `ACTIONS`: 14 обработчиков; `shoot` создаёт снаряд.
- `applyDamage`: `armor` снижает урон.
- `applyDamage`: `weak_point` вне зоны + `only_weak_point_vulnerable` → 0.
- `applyDamage`: `shield_front` с `damage_multiplier: 0.0` → 0.

**Triggers:**

- `kill_count`: убито N → срабатывает.
- `pickup_all`: собраны все → срабатывает.
- `once: true`: второй раз не срабатывает.
- `enabled: false`: не проверяется.
- `all_of`: оба условия → срабатывает.
- `any_of`: одно из двух → срабатывает.
- `victory > defeat` при одновременном выполнении.

**Public:**

- `/games/{draft-slug}` → 404.
- `/play/{slug}` → 200 для `published`.
- `/sitemap.xml` не содержит `draft`-игр и `/play/*`.
- Rate-limit: 61-й запрос → 429.
- SEO: `og:image` присутствует на `/games/{slug}`.

### 2.4. Headless-проверка (`cli/validate-levels.php`)

```bash
php cli/validate-levels.php [--game=game-001] [--level=level-002] [--json]
```

**Что проверяет (структурная проверка):**

- `start` есть и ровно один.
- `exit` есть (где требуется).
- Все `pickup` достижимы от `start` (BFS/DFS по графу проходимых клеток с учётом `solid`, `climbable`, `hangable`, `walkable`, `swimmable`, `portal_to`).
- `exit` достижим.
- Композиты в границах, не перекрываются.
- Body-спрайты соответствуют `composite.sprites`.
- Нет «сиротских» body-спрайтов вне композитов.

**Не проверяет:** AI, физику прыжков, реальную играбельность.

**Применение:**

- Ручной запуск администратором.
- Cron еженедельно → отчёт в `data/level-validation.log`.
- Не блокирует создание игры.

---

## 3. Сценарный чек-лист (ручной прогон)

Прогон по всем блокам 1–12. Результат — таблица «✅ / ❌ / ⚠» с комментариями. Каждый пункт — конкретное действие с ожидаемым результатом.

### 3.1. Блок 1 — Фундамент

- [ ] Установка по README за ≤ 5 мин.
- [ ] `php cli/create-admin.php --username=admin --password=secret` — создаёт админа.
- [ ] `php cli/seed-genres.php` — создаёт 5 жанров.
- [ ] Вход по имени/паролю работает.
- [ ] Выход завершает сессию.
- [ ] Дашборд доступен после входа.
- [ ] `/data/users.json` — 403/404 из браузера.
- [ ] POST без CSRF → 403.

### 3.2. Блок 2 — Жанры и категории

- [ ] Список жанров — 5 записей после seed.
- [ ] ▲▼ меняет `sort_order`.
- [ ] Создание жанра: slug автогенерируется; дубликат → ошибка.
- [ ] Смена `engine` жанра → модалка; после подтверждения перезапись `engine_config`, `default_legend`, `default_controls`, `default_sounds`.
- [ ] Удаление используемого жанра — блокируется.
- [ ] Создание категории с иерархией; глубина ≤ 5.
- [ ] Цикл A→B→A — блокируется.
- [ ] Удаление категории с дочерними — блокируется.
- [ ] Импорт/экспорт `genres.json` / `categories.json` работают.

### 3.3. Блок 3 — AI и паттерны

- [ ] `php cli/seed-ai-models.php` — 50 моделей.
- [ ] `php cli/seed-movement-patterns.php` — 50 паттернов.
- [ ] Встроенные read-only; кнопка «Клонировать» работает.
- [ ] Кастомная модель: создание, редактирование, удаление.
- [ ] Визуализация 50 траекторий в разделе «Паттерны».
- [ ] Удаление используемой модели/паттерна — блокируется.

### 3.4. Блок 4 — Карточки игр

- [ ] Создание игры с обязательными полями.
- [ ] Обложка: загрузка JPG/PNG/WebP/GIF; ресайз 640×480 WebP; `cover-prev.webp`.
- [ ] PDF — ошибка; >5 MB — ошибка.
- [ ] Slug автогенерация; смена после сохранения — модалка.
- [ ] Смена жанра — модалка; `controls`/`sounds` перезаписываются.
- [ ] Удаление игры — модалка с вводом `title`.

### 3.5. Блок 4.5/6 — Банк эмодзи

- [ ] PNG 32×32 → сохраняется как 32×32 (`source_size: {32,32}`), **без изменения размеров**.
- [ ] PNG 300×300 → ошибка.
- [ ] GIF → первый кадр, PNG.
- [ ] SVG с `rect`+`path`+`linearGradient` → санитизируется, `.svg`, `sanitized_hash`.
- [ ] SVG с `<script>` → элемент удалён.
- [ ] SVG без фигур → ошибка.
- [ ] Анимация из 3 кадров → валидна.
- [ ] Анимация с 1 кадром → ошибка.
- [ ] Анимация с суммой > 10000 мс → ошибка.
- [ ] PUA-кодпоинты: e001 → U+E000, e002 → U+E001; после удаления e003 следующий — U+E003.
- [ ] Публичный `/api/emoji-bank.json` — `{codepoint: {type, url/frames}}`.
- [ ] Экспорт/импорт ZIP работают.
- [ ] Zip-slip (`../evil.php`) → отказ.

### 3.6. Блок 5 — AI и паттерны в легенде (подготовка)

- [ ] Разделы «AI-модели» и «Паттерны» доступны; фильтры работают.
- [ ] Кастомные модели/паттерны создаются.

### 3.7. Блок 7 — Карточка игры и легенда

- [ ] Пять вкладок активны.
- [ ] Легенда: 9 ролей создаются с соответствующими полями.
- [ ] Emoji-picker: вкладки «Стандартные» и «Банк».
- [ ] Композиты 1×1, 1×2, 2×1, 2×2, 3×2, 2×3 — создаются.
- [ ] Недопустимые размеры (3×1, 1×3, 3×3) — отсутствуют.
- [ ] Действия: 14 типов; `shoot.projectile` — селект только `projectile` emoji.
- [ ] `behavior_mode` ai/trajectory/hybrid — переключение работает.
- [ ] Боевые свойства моба: `hp`, `armor`, `weak_point`, `shield_front`, `drop_emoji`, `explosion_on_death`.
- [ ] Импорт из жанра; клонирование.
- [ ] Удаление emoji с ссылками — предупреждение; ссылки удаляются.

### 3.8. Блок 8 — Уровни и редактор

- [ ] Список уровней: 0 → «Пока нет уровней».
- [ ] Создание уровня — заполняется emoji-пустотой.
- [ ] Визуальный grid — 32×24 / 32×N / 24×M.
- [ ] Размещение композита целиком (N клеток).
- [ ] Выход за границы — игнорируется.
- [ ] Перекрытие — модалка «Перезаписать?».
- [ ] Правый клик по клетке композита → весь композит удалён.
- [ ] Undo/Redo — до 100 операций.
- [ ] Синхронизация textarea и визуального grid.
- [ ] Textarea: `[e001]` ↔ PUA.
- [ ] Валидация в реальном времени.
- [ ] Размеры: `static-screen` — только 24×32 (кнопки ± строка/столбец disabled).
- [ ] Автосохранение в `localStorage` через 30 сек.
- [ ] Превью уровня: `thumb.webp` 320×240, анимированные emoji — первый кадр.
- [ ] Кнопка «Тест» — открывает `/play/{slug}/{level_id}`.

### 3.9. Блок 9 — Управление, звуки, настройки

- [ ] Вкладка «Управление»: список действий по движку.
- [ ] Назначение клавиши: «Нажмите клавишу» → захват.
- [ ] До 2 клавиш на действие.
- [ ] Конфликты — предупреждение.
- [ ] Вкладка «Настройки»: общие, физика, камера, ямы.
- [ ] Диапазоны: `speed_scale = 3.0` → ошибка.
- [ ] Блок «Звуки»: до 10 событий; прослушивание работает.
- [ ] `frequency_hz = 5000` → ошибка.
- [ ] Сброс к дефолтам жанра.
- [ ] Копирование настроек из другой игры того же движка.

### 3.10. Блок 10 — Боевая система и триггеры

- [ ] Ближний бой игрока: урон мобу, смерть.
- [ ] Стрельба игрока: снаряд, попадание, урон.
- [ ] Стомп: прыжок на моба, `morph_to`.
- [ ] Спецприём: `screen_clear`, заряды.
- [ ] Броня, `weak_point`, `shield_front`.
- [ ] Смерть моба: `score_value`, `drop_emoji`, `explosion_on_death`.
- [ ] Смерть игрока: `lives--`, `invulnerability_ms`.
- [ ] Combo: множитель работает.
- [ ] Time bonus при победе.
- [ ] Триггеры: `kill_count`, `pickup_all`, `time_elapsed`, `player_in_zone`.
- [ ] `once: true`, `enabled: false`.
- [ ] `all_of`, `any_of`.
- [ ] `victory_conditions`, `defeat_conditions`; победа приоритетнее.
- [ ] «Тест поведения» — моб действует.
- [ ] «Проверить триггеры» — лог срабатываний.

### 3.11. Блок 11 — Движки

- [ ] `static-screen` — Lode Runner-подобная игра проходима.
- [ ] `static-screen` — Cauldron/Robin-подобная (8-направленное движение).
- [ ] `static-screen` — Bomberman-подобная (4-направленное движение).
- [ ] `vertical-scroll` — River Raid-подобная: скролл, топливо, заправки.
- [ ] `vertical-scroll` — Galaga-подобная: формации, паттерны.
- [ ] `horizontal-scroll` — Mario-подобная: прыжки, чекпоинты, трубы.
- [ ] `horizontal-scroll` — Myth-подобная: HP, ближний бой, боссы.
- [ ] Применение `engine_config`: `speed_scale`, `sprite_flicker`, `input_responsiveness`, `fps`, `show_hud`.
- [ ] Один движок обслуживает две игры одного типа — без изменений в коде.
- [ ] `sprite_flicker` — заметное мерцание.

### 3.12. Блок 12 — Публичная часть и бэкапы

- [ ] `/` — лендинг открывается.
- [ ] `/games` — только `published`; фильтры работают.
- [ ] `/games/{slug}` — обложка, описание, галерея уровней.
- [ ] `/genres/{slug}` — игры жанра.
- [ ] `/categories/{slug}` — игры категории и потомков.
- [ ] `/emoji` — витрина.
- [ ] `/play/{slug}` — игра запускается.
- [ ] `draft`-игра → 404.
- [ ] SEO: `<title>`, `meta description`, OG, canonical.
- [ ] `/sitemap.xml` — только `published`.
- [ ] `/robots.txt` — закрывает `/admin/`, `/play/`, `/api/`, `/data/`.
- [ ] Rate-limit: 61-й запрос → 429.
- [ ] Bulk publish/draft/delete.
- [ ] Копирование настроек.
- [ ] Аналитика: `views++`, `plays++`.
- [ ] `cli/backup.php` — создаёт архив; `cli/restore.php` — восстанавливает.
- [ ] Drill восстановления выполнен.

---

## 4. «Золотой путь»

### 4.1. Назначение

Разработчик или администратор, **не знакомый с проектом**, создаёт игру с нуля по USER-GUIDE без подсказок. Время — **≤ 1 час**.

### 4.2. Сценарий

1. **Открыть README**, установить CMS (≤ 5 мин).
2. **Войти в админку.**
3. **Создать жанр** (или использовать существующий из seed).
4. **Создать игру:** название, жанр, описание, обложка.
5. **Загрузить 5 эмодзи в банк** (PNG/GIF/SVG).
6. **Создать одну анимацию** из 3 кадров.
7. **Заполнить легенду:** 8+ элементов (фон, преграда, моб, предмет, выход, старт, снаряд, коллидер).
8. **Настроить боевые свойства моба** (hp, drop_emoji).
9. **Нарисовать 3 уровня** в визуальном редакторе.
10. **Добавить 2 триггера** на одном уровне.
11. **Назначить управление** (проверить конфликты).
12. **Настроить 5 звуков.**
13. **Выбрать AI-модель моба** (`chase_with_vision`) и паттерн движения.
14. **Опубликовать игру.**
15. **Открыть `/games/{slug}`** → увидеть карточку; клик по уровню → играть.

### 4.3. Критерии успеха

- Пройдено **все шаги** без подсказок (только USER-GUIDE).
- Время **≤ 60 минут**.
- Игра **проходима** (минимум один уровень).
- Не было **критических ошибок** в консоли/на сервере.

При неудаче — запись в `docs/PROJECT-REPORT.md` → «Проблемы золотого пути» → исправление USER-GUIDE или UX → повторный прогон.

---

## 5. Документация

### 5.1. `README.md`

**Для разработчика, разворачивающего CMS.**

**Структура:**

1. Название, версия, скриншоты.
2. Что это (1 абзац).
3. Требования (PHP 8.1+, `gd`/`imagick`, `intl`, `libxml`, `fileinfo`, `json`).
4. Установка за 5 шагов:
   - скачать;
   - `composer install` (опционально);
   - `cp config/secrets.example.php config/secrets.php`;
   - `php cli/create-admin.php`;
   - `php cli/seed-genres.php` + `seed-ai-models.php` + `seed-movement-patterns.php`.
5. Настройка nginx/Apache (примеры для `/data/`, `/config/`, `/public/media/`, `/public/media/emoji-bank/`).
6. Локальный запуск (`php -S localhost:8000 -t public/`).
7. Cron для бэкапов.
8. Ссылки на USER-GUIDE, DEV-GUIDE, SECURITY, CHANGELOG.

**Проверка окружения:**

```bash
php -r "echo PHP_VERSION;"
php -r "echo extension_loaded('gd') && function_exists('imagewebp') ? 'gd-webp ok' : 'no';"
php -r "echo extension_loaded('intl') ? 'intl ok' : 'no';"
php -r "echo extension_loaded('libxml') ? 'libxml ok' : 'no';"
php -r "echo extension_loaded('fileinfo') ? 'fileinfo ok' : 'no';"
```

Все проверки → `ok`.

### 5.2. `USER-GUIDE.md`

**Для администратора CMS.**

**Структура:**

1. Вход в админку.
2. Как создать жанр (или использовать готовый).
3. Как создать игру: название, жанр, описание, обложка, категории.
4. Как загрузить эмодзи в банк (PNG/GIF/SVG).
5. Как создать анимацию из кадров.
6. Как заполнить легенду: роли, свойства, действия, композиты.
7. Как настроить боевые свойства моба.
8. Как нарисовать уровень: визуальный grid, композиты, валидация.
9. Как добавить триггеры и условия победы/поражения.
10. Как назначить клавиши.
11. Как настроить звуки.
12. Как выбрать AI-модель моба и паттерн движения; тест поведения.
13. Как опубликовать игру и проверить публичную страницу.
14. Как делать бэкапы.
15. FAQ: «не сохраняется», «звук не играет», «SVG отклоняется», «уровень не проходится».

**Скриншоты** — по одному на каждый ключевой шаг. Формат — Markdown с изображениями в `docs/screenshots/`.

### 5.3. `DEV-GUIDE.md`

**Для разработчика, поддерживающего CMS.**

**Структура:**

1. Архитектура: три движка по типу мира, shared-модули.
2. Структура каталогов.
3. Контракт `EngineBase` (`init/start/stop/pause/resume/destroy`).
4. Форматы данных (`genres.json`, `categories.json`, `games/*.json`, `emoji-bank.json`, `ai-models.json`, `movement-patterns.json`).
5. Как добавить AI-модель: код в `ai-core.js` + фикстура + библиотека.
6. Как добавить паттерн движения.
7. Как добавить действие.
8. Как добавить новый движок (если появится 4-й тип мира).
9. Формат `level.grid` и композитов.
10. Процесс тестирования (`tests/run.php`).
11. Соглашения по коду (PSR-12, ESLint).
12. Как делать миграции JSON-схем.
13. Как расширять `engine_config` (белый список полей).

### 5.4. `SECURITY.md`

**Структура:**

1. Модель угроз (кратко).
2. Аутентификация: bcrypt, сессии, CSRF.
3. Изоляция SVG (`GeometrySvgSanitizer` + CSP + `<img>`).
4. Изоляция `/media/emoji-bank/` (nginx/Apache).
5. Изоляция `/data/` и `/config/`.
6. Rate-limiting.
7. Известные ограничения.
8. Как сообщить об уязвимости.

### 5.5. `CHANGELOG.md`

**Формат Keep a Changelog.**

```markdown
# Changelog

## [1.0.0] — 2026-10-22

### Added
- Flat-file CMS с тремя движками (`static-screen`, `vertical-scroll`, `horizontal-scroll`).
- 5 жанров (Lode Runner, Arcade adventure, Платформеры, Myth-like, Вертикальные шутеры).
- Библиотеки: 50 AI-моделей, 50 паттернов движения.
- Банк эмодзи: PNG/GIF/SVG (санитизация до геометрии), PUA-кодпоинты, анимации.
- Легенда: 9 ролей, композиты 1×1…3×2, 14 действий.
- Уровни: emoji-сетка 32×24, визуальный редактор, композиты, триггеры.
- Боевая система: ближний бой, стрельба, стомп, спецприём, HP, weak_point, shield_front.
- Триггеры: 13 условий, 15 действий.
- Публичная часть: каталог, карточка игры, `/emoji`, SEO.
- Бэкапы: `cli/backup.php`, `cli/restore.php`.

### Fixed
- ...

### Security
- SVG санитизируется до геометрии + градиентов.
- XXE-защита.
- CSP на SVG.

## [Unreleased]
- Будущие идеи.
```

### 5.6. `docs/adr/`

**Architecture Decision Records** — 8 файлов:

- `0001-flat-file-vs-database.md`;
- `0002-three-engines-by-world-type.md`;
- `0003-svg-geometry-only.md`;
- `0004-emoji-as-tiles.md`;
- `0005-composite-objects.md`;
- `0006-pua-codepoints-for-bank.md`;
- `0007-animations-as-frame-sequences.md`;
- `0008-rate-limiting-file-based.md`.

**Формат:** контекст → решение → последствия. 5–15 строк.

### 5.7. `docs/PROJECT-REPORT.md`

**Структура:**

1. Что сделано — сводка по блокам 1–12.
2. Статистика кода — строк, файлов, тестов.
3. Что не сделано — список из разделов «Что не делаем» каждого блока, с обоснованием.
4. Известные ограничения.
5. Roadmap (будущие улучшения).
6. Результаты приёмки — сводная таблица по чек-листу §3.
7. Результаты аудита безопасности — 0 критичных, N средних исправлено, M принято.
8. Результаты drill восстановления — дата, время, результат.
9. Результаты «золотого пути» — время, проблемы, исправления.

### 5.8. `VERSION`

```
1.0.0
```

Читается CMS; отображается в футере админки и в `audit.log`.

---

## 6. Аудит безопасности

Финальный аудит по чек-листу из блока 3, §12. Результат — запись в `SECURITY.md` → раздел «Аудит (дата, версия)».

**Если найдены дефекты** — исправляются (единственная категория изменений, допустимая в блоке 13); записи в `CHANGELOG.md` → `Fixed` и `Security`.

**Повторный аудит** после исправлений.

---

## 7. Финальные критерии приёмки проекта

### 7.1. Функциональные (свод по блокам 1–12)

Все критерии блоков 1, 2, 3, 4, 4.5/6, 5, 7, 8, 9, 10, 11, 12 выполняются. Итоговая сводка — в `docs/PROJECT-REPORT.md`.

Ключевые инварианты:

1. **Установка.** На чистой машине по README — ≤ 30 мин.
2. **Аутентификация.** Вход/выход/CSRF/защита `/data/`.
3. **Жанры и категории.** CRUD, иерархия, seed 5 жанров.
4. **AI и паттерны.** 50 + 50 после seed; встроенные read-only.
5. **Игры.** CRUD карточки; 5 вкладок; обложка; обязательные поля.
6. **Банк эмодзи.** PNG/GIF/SVG (без растеризации SVG, без изменения размеров PNG/GIF); PUA-кодпоинты; анимации; публичный API.
7. **Легенда.** 9 ролей; композиты 1×1…3×2; 14 действий; `behavior_mode`.
8. **Уровни.** Визуальный grid с фиксированными размерами; композиты; триггеры; условия победы/поражения.
9. **Три движка.** `static-screen`, `vertical-scroll`, `horizontal-scroll` — работают; один движок обслуживает игры одного типа мира.
10. **Управление/звуки/настройки.** Назначение клавиш; параметры движков; до 10 звуков; атомарное сохранение.
11. **AI и паттерны в легенде.** Выбор; 3 режима; тест поведения; boss-модели; формации.
12. **Боевая система.** Атаки игрока, урон, HP, reactions, очки.
13. **Триггеры.** 13 условий, 15 действий.
14. **Публичная часть.** Каталог, карточка, жанры/категории, `/emoji`, `/play/*`; SEO; `sitemap.xml`; `robots.txt`; rate-limiting; аналитика.
15. **Бэкапы.** `backup.php`, `restore.php`, cron, drill.

### 7.2. Нефункциональные

16. **Безопасность.** OWASP Top 10 проверен; 0 критичных уязвимостей; SVG изолирован; ZIP безопасен; CSRF на всех POST.
17. **Тесты.** `tests/` — ≥ 80 тестов; все проходят; прогон ≤ 60 сек.
18. **Бэкапы.** `backup.php` и `restore.php` работают; cron-пример в README; drill выполнен.
19. **Документация.** `README.md`, `USER-GUIDE.md`, `DEV-GUIDE.md`, `SECURITY.md`, `CHANGELOG.md`, `docs/adr/0001–0008`, `docs/PROJECT-REPORT.md` — полные, актуальные.
20. **«Золотой путь».** Создание игры с нуля по USER-GUIDE за ≤ 1 час.
21. **Headless-проверка.** `cli/validate-levels.php` работает; демо-игры проходят.
22. **VERSION = 1.0.0**; тег `v1.0.0` в git.
23. **Обратная совместимость.** Все критерии блоков 1–12 выполняются; отсутствие регрессий.

### 7.3. Передаточный пакет

В релиз `v1.0.0` входит:

- **Исходный код** (git-репозиторий с тегом `v1.0.0`).
- **README.md, USER-GUIDE.md, DEV-GUIDE.md, SECURITY.md, CHANGELOG.md.**
- **docs/adr/0001–0008.md.**
- **docs/PROJECT-REPORT.md** (финальный отчёт).
- **docs/screenshots/** — скриншоты для USER-GUIDE.
- **tests/** — зелёные.
- **cli/** — `create-admin.php`, `seed-genres.php`, `seed-ai-models.php`, `seed-movement-patterns.php`, `backup.php`, `restore.php`, `validate-levels.php`.
- **config/secrets.example.php.**
- **nginx.sample.conf**, **.htaccess** (примеры).
- **VERSION** = `1.0.0`.
- **Демо-данные** (опционально): 5 демо-игр из блоков 7–8, экспортированные в ZIP для импорта.

**Проект завершён.**

---

## 8. Оценка

**Ориентир:** 3–4 человеко-дня на разработчика (или ~1,5 рабочих дня с ИИ-ассистентом).

Основное время:

- **Автоматические тесты** — 80+ тестов на ключевые модули (≈1 день).
- **Сценарный прогон** — 60+ пунктов чек-листа + исправления (≈1 день).
- **«Золотой путь»** — прогон по USER-GUIDE, исправления (≈0,5 дня).
- **Документация** — README, USER-GUIDE, DEV-GUIDE, SECURITY, ADR, PROJECT-REPORT (≈1 день).
- **Аудит безопасности** + исправления (≈0,5 дня).
- **Headless-проверка** (`validate-levels.php`) (≈0,3 дня).
- **Финальный отчёт** (≈0,3 дня).

---

## 9. Что не делаем в блоке 13 и почему

- **Новые фичи** — только стабилизация и исправления.
- **Изменение архитектуры** — только дефекты и уязвимости.
- **Полная нагрузочная проверка** (1000 RPS) — вне объёма; rate-limiting из блока 3/12.
- **Внешний пентест** — вне объёма; внутренний аудит по OWASP Top 10.
- **Миграция на БД** — flat-file — осознанное решение (ADR-0001).
- **Мультиязычность, аккаунты игроков, монетизация** — вне объёма с начала проекта.
- **Полная антибот-защита (CAPTCHA/Hashcash)** — rate-limiting достаточно.
- **Публикация CMS в marketplace** — вне объёма.
- **Мобильное управление** — только клавиатура.
- **Облачные сохранения прогресса** — вне объёма.

---

## 10. Закрытие проекта

После выполнения блока 13:

- **CMS готова к использованию** — администратор создаёт игры; разработчик поддерживает и расширяет.
- **Передаточный пакет** (см. §7.3) собран и передан.
- **Тег `v1.0.0`** в git.
- **`docs/PROJECT-REPORT.md`** подписан (руководитель проекта / владелец).
- **Drill восстановления** выполнен; отчёт в `tests/backup-drill.md`.
- **«Золотой путь»** пройден; отчёт в `docs/PROJECT-REPORT.md`.
- **Аудит безопасности** пройден; 0 критичных.

**Проект завершён.**

---

## 11. Итоговая карта всех блоков

| Блок | Содержание | Статус |
|---|---|---|
| 1 | Общее описание, архитектурные решения, технологический стек | ✅ |
| 2 | Структура проекта и хранение данных | ✅ |
| 3 | Безопасность и аутентификация | ✅ |
| 4 | Админка: общее, жанры, категории | ✅ |
| 5 | Библиотеки AI-моделей и паттернов движения | ✅ |
| 6 | Банк эмодзи (PNG/GIF/SVG, анимации, публичный API) | ✅ |
| 7 | Карточка игры и легенда | ✅ |
| 8 | Уровни и редактор уровней | ✅ |
| 9 | Управление, звуки, настройки движка | ✅ |
| 10 | Боевая система и триггеры | ✅ |
| 11 | Движки (`static-screen`, `vertical-scroll`, `horizontal-scroll`) | ✅ |
| 12 | Публичная часть и бэкапы | ✅ |
| 13 | Тестирование, документация, критерии приёмки | ✅ |

**13 блоков. Финальная версия 1.0.0.**

---

**Конец блока 13 из 13.**

**ТЗ завершено. Проект готов к реализации.**






