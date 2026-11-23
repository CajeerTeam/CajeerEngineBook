# Руководство администратора · CajeerEngine Book 1.2.0

**Установка, эксплуатация, обновления и восстановление CajeerEngineBook.**

[Главная](../../README.md) · [Продукт](../product/README.md) · [Развёртывание](../product/deployment.md) · [Центр релизов](../releases/README.md)

<!-- Compatibility marker for tests/admin-docs.py: Current PostgreSQL schema: 7. -->

> [!IMPORTANT]
> Текущая требуемая схема PostgreSQL — **7**; встроенное ядро CajeerEngine — **2.1.0**. Руководства старых выпусков сохраняют контекст своих версий. Не запускайте описанные в них миграции повторно только из-за их присутствия в документации.

## С чего начать

| Задача | Руководство |
| --- | --- |
| Подготовить и принять выпуск `1.2.0` | [Условия выпуска, установка, rollback и recovery](release-120.md) |
| Развернуть новую установку | [Установка и текущая эксплуатация](operations.md) |
| Настроить секреты, PostgreSQL, Redis, TLS и SSO | [Справочник конфигурации](configuration.md) · [SkiF4er ID](skif4er-id.md) |
| Проверить состояние и выполнить диагностику | [CLI-команды](cli-reference.md) · [проверки](verification.md) |
| Реагировать на неисправности | [Инциденты и troubleshooting](incidents.md) |
| Подготовить допуск к production | [Production qualification](production-qualification.md) · [нагрузочные тесты](load-qualification.md) |
| Проверить и опубликовать подписанные артефакты | [Подписанные релизы](signed-releases.md) · [встроенное ядро](engine-core.md) |

## Обновления и восстановление

**Путь релиза:** подписанный GitHub Release → проверка владельцем → доверенный host-agent → проверенный пакет → техническое окно обслуживания → активация → контроль состояния.

| Версия | Что добавлено | Инструкция |
| --- | --- | --- |
| **1.2.0** | Production Auto Update Release | [Приёмка и безопасный переход](release-120.md) |
| **1.1.7** | История операций и очищенная диагностика | [Операции и история](update-history-117.md) |
| **1.1.6** | Проверки по расписанию и уведомления | [Планировщик](update-scheduler-116.md) |
| **1.1.5** | Верифицируемые резервные копии | [Backup / recovery / rollback](update-recovery-115.md) |
| **1.1.4** | Доверенный root-owned агент | [Безопасное выполнение](update-execution-114.md) |
| **1.1.3** | Панель обновлений владельца | [Admin Update Center](update-center-113.md) |
| **1.1.2** | Обнаружение подписанных релизов | [GitHub discovery](update-discovery-112.md) |
| **1.1.1** | Проверка официальной публикации GitHub Release | [Инфраструктура релизов](release-111.md) |
| **1.1.0** | Совокупные проверки выпуска | [Release acceptance](release-110.md) |
| **Ранние версии** | Контролируемые обновления и повторные проверки безопасности | [Secure updates](secure-updates.md) · [release candidate](release-candidate.md) |

> [!WARNING]
> `Fetch` и `Preflight` не активируют выпуск. Для `Apply`, отката и recovery оператор обязан проверить резервные копии, остановить **все** источники записи и обеспечить разрешённое окно обслуживания. Веб-интерфейс не заменяет права оператора хоста.

## Данные, безопасность и надёжность

| Область | Руководства |
| --- | --- |
| Безопасность | [Жизненный цикл ключей и сессий](security-lifecycle.md) · [защитные настройки](security-hardening.md) |
| Резервирование и аварийное восстановление | [Disaster recovery / PITR](disaster-recovery.md) |
| Наблюдаемость | [Метрики и алерты](observability.md) |
| Распределённый режим | [Реплики, Sentinel, воркеры, media](distributed-reliability.md) |
| Производительность | [Бенчмарки](performance.md) · [SQL-производительность](postgres-performance.md) |
| Аналитика и приватность | [Аналитика Workspace](analytics.md) |
| Семантический поиск | [Embeddings и hybrid search](search-embeddings.md) |
| AI-функции | [Чат документации](documentation-chat.md) · [AI-помощник редактора](editor-ai.md) |

## Импорт существующей документации

| Источник | Руководство |
| --- | --- |
| Mintlify | [Импорт `docs.json` / MDX](mintlify-import.md) |
| Docusaurus | [Sidebars, версии, локализации](docusaurus-import.md) |
| GitBook | [Spaces, Git Sync, API и вложения](gitbook-import.md) |
| ReadMe | [Guides, API Reference, Recipes](readme-import.md) |
| Fumadocs | [Content Collections и `meta.json`](fumadocs-import.md) |

**Обязательные границы эксплуатации:** файловый режим рассчитан на один хост с приватным постоянным хранилищем. PostgreSQL-режиму нужны PostgreSQL 16, схема 7 и primary с правом записи. Реплики приложения требуют общего POSIX-хранилища; в документированном распределённом режиме необходим Redis. Sentinel, HA PostgreSQL, внешние секреты и резервирование остаются обязанностью оператора.

Проверка `/health/ready`, «зелёный» CI и короткий file-mode benchmark **не** заменяют тест восстановления и нагрузочную квалификацию инфраструктуры. Используйте независимый доверенный ключ релиза и храните секреты, ключи и бэкапы вне публичных каталогов.
