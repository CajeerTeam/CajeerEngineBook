# Архитектура и границы ответственности

[Продукт](README.md) · [Топологии](deployment.md) · [Операционная безопасность](security.md)

## Компоненты системы

```mermaid
flowchart TB
    subgraph CLIENT["Клиенты"]
        R["Reader"]
        S["Studio"]
        API["REST / интеграции"]
    end
    subgraph APPLICATION["CajeerEngineBook · PHP 8.4+"]
        ROUTER["Маршруты и контроллеры"]
        AUTH["Сессии · RBAC · OIDC"]
        DOC["Контент · версии · публикация"]
        INDEX["Поиск · аналитика · интеграции"]
    end
    subgraph DATA["Данные"]
        P["Файлы или PostgreSQL 16"]
        M["Приватные медиа"]
        C["Redis · опционально / distributed"]
    end
    R --> ROUTER
    S --> ROUTER
    API --> ROUTER
    ROUTER --> AUTH
    AUTH --> DOC
    DOC --> P
    DOC --> M
    INDEX --> P
    INDEX -.-> C
    DOC --> INDEX
    H["Оператор хоста"] --> AG["Root-owned update agent"]
    AG --> UP["Проверенный Docker updater"]
```

### Приложение

Веб-часть реализует Reader, Studio, API и административные маршруты. Контроллеры и сервисы находятся в `src/`, публичный корень — `public/`. Фоновые задачи запускаются отдельным CLI-процессом `bin/worker.php`.

### Встроенное ядро

Папка `core/` содержит **ограниченный набор** классов CajeerEngine `2.1.0`: контейнер сервисов, шаблонизатор и файловые операции обновления. Это **не** интеграция всей CMS CajeerEngine, её БД, панели администратора и миграций. Детали — [embedded engine](../admin/engine-core.md).

### Данные и фоновые задачи

| Режим | Основное состояние | Предназначение |
| --- | --- | --- |
| File | Приватные файлы и медиа | Один узел, упрощённая локальная эксплуатация |
| PostgreSQL | SQL-контент, identity, индекс, аудит + приватные медиа | Производственный SQL-режим |
| Distributed | PostgreSQL + Redis + общее POSIX-медиа | Несколько узлов приложения/воркеров с внешней эксплуатацией отказоустойчивости |

В PostgreSQL-режиме требуется схема **7** и доступный для записи primary. Redis в одноузловой конфигурации необязателен, в документированном распределённом режиме обязателен. Не применяйте transaction-mode pooling там, где нужны session advisory locks.

### Граница привилегий обновления

```mermaid
sequenceDiagram
    actor Owner as Владелец установки
    participant Web as /admin/updates (PHP)
    participant Agent as Host-agent (root, Unix socket)
    participant Updater as bin/update.py
    Owner->>Web: Проверить релиз / подготовить кандидата
    Web->>Agent: Авторизованный RPC fetch/preflight
    Agent->>Updater: Проверить пакет и совместимость
    Updater-->>Agent: Отчёт без секретов
    Agent-->>Web: Ограниченный статус
    Note over Owner,Updater: Оператор отдельно ограждает трафик, проверяет бэкап и выдаёт разрешение
    Owner->>Web: Запрос Apply при наличии допуска
    Web->>Agent: RPC apply, привязанный к кандидату
    Agent->>Updater: Проверка, бэкап, активация, health
```

`PHP` не запускает Docker напрямую и не получает root-доступ к файлам агента. Восстановление, откат и вмешательство при незавершённой миграции выполняются оператором хоста, а не через обычный пользовательский интерфейс.

## Где читать реализацию

- [Конфигурация и секреты](../admin/configuration.md)
- [Эксплуатация и установка](../admin/operations.md)
- [Надёжность распределённой системы](../admin/distributed-reliability.md)
- [Аудит и жизненный цикл ключей](../admin/security-lifecycle.md)
- [Подписанные релизы](../admin/signed-releases.md)
