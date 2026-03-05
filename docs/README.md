# Документация проекта

Этот раздел организован по практической структуре: от быстрого старта к прикладным сценариям и справочнику.

## С чего начать

1. [Руководство: быстрый старт и базовая интеграция](tutorial-quick-start.md)
2. [Справочник: параметры и контракты](reference-configuration.md)

## Прикладные задачи

- [Практика: уровни и программы лояльности](howto-loyalty-levels.md)
- [Практика: реферальная программа](howto-referral-program.md)
- [Сложные примеры: начисления, холды, возвраты, уровни](examples-advanced-scenarios.md)

## Риски и безопасность

- [Разбор: риски, антифрод и защита бизнес-логики](explanation-fraud-controls.md)

## Что где искать

- Если нужно быстро запустить компонент: `tutorial-quick-start.md`.
- Если нужно внедрить сложную механику (уровни, отложенные начисления, реферальные сценарии): `howto-*` и `examples-advanced-scenarios.md`.
- Если нужно проверить параметры, события и ограничения: `reference-configuration.md`.
- Если нужно выстроить контроль мошенничества: `explanation-fraud-controls.md`.

## Проверки качества

- Локальный запуск полного набора проверок: `composer qa`.
- Состав набора: `parallel-lint`, `php-cs-fixer`, `rector`, `phpunit`, `phpstan` (уровень 8 + strict/deprecation rules), `psalm --taint-analysis`, `composer audit`.
- Для автоисправлений доступны команды: `composer cs:fix` и `composer rector:fix`.

## CI и релизы

- Workflow CI: `.github/workflows/php.yml`.
- Security workflow: `.github/workflows/security.yml`.
- Матрица CI: PHP `8.1` и `8.3`, MySQL `8.0`.
- История изменений: `CHANGELOG.md`.
