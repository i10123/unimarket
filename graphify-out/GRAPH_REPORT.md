# Graph Report - unimarket  (2026-11-13)

## Corpus Check
- Corpus is ~22,781 words - fits in a single context window. You may not need a graph.

## Summary
- 236 nodes · 498 edges · 32 communities (12 shown, 20 thin omitted)
- Extraction: 82% EXTRACTED · 18% INFERRED · 0% AMBIGUOUS · INFERRED: 90 edges (avg confidence: 0.85)
- Token cost: not captured (subagent usage data was unavailable)

## Community Hubs (Navigation)
- Материалы и покупки
- Кабинет и вход
- Проверка форм
- Архитектура и требования
- Настройка и администрирование
- Карточки и общие функции
- Карточки и избранное
- Кабинет и история баллов
- Общие элементы интерфейса
- Покупки и баллы
- Каталог и поиск
- Страницы маркетплейса
- Лицензии шрифтов
- Настройки аккаунта
- Иконка раскрытия
- Иконка показа пароля
- Иконка скрытия пароля
- Заполненное избранное
- Контур избранного
- Иконка входа
- Логотип УниМаркета
- Иконка редактирования
- Добавление пользователя
- Иконка профиля
- Обновление БД на устройстве
- Обзор кабинета
- Страница избранного
- Публикация материала
- История покупок
- История продаж

## God Nodes (most connected - your core abstractions)
1. `el()` - 29 edges
2. `ApiError` - 25 edges
3. `api()` - 21 edges
4. `showStatus()` - 16 edges
5. `{closure#1}()` - 15 edges
6. `startPage()` - 14 edges
7. `Market` - 13 edges
8. `{closure#1}()` - 13 edges
9. `db()` - 13 edges
10. `price()` - 11 edges

## Surprising Connections (you probably didn't know these)
- `deleteUser()` --calls--> `AuthHandler`  [INFERRED]
  scripts/admin.php → backend/AuthHandler.php
- `resetPassword()` --calls--> `readPassword()`  [INFERRED]
  scripts/admin.php → backend/validation.php
- `deleteUser()` --calls--> `db()`  [INFERRED]
  scripts/admin.php → backend/bootstrap.php
- `resetPassword()` --calls--> `db()`  [INFERRED]
  scripts/admin.php → backend/bootstrap.php
- `showUser()` --calls--> `db()`  [INFERRED]
  scripts/admin.php → backend/bootstrap.php

## Import Cycles
- None detected.

## Communities (32 total, 20 thin omitted)

### Community 0 - "Материалы и покупки"
Cohesion: 0.10
Nodes (11): {closure#1}(), {closure#1}(), ApiError, postInput(), transaction(), {closure#1}(), FileStore, {closure#2}() (+3 more)

### Community 1 - "Кабинет и вход"
Cohesion: 0.10
Nodes (18): accountData(), {closure#1}(), {closure#1}(), AuthHandler, checkAuthLimit(), currentUser(), renewSession(), requireUser() (+10 more)

### Community 2 - "Проверка форм"
Cohesion: 0.12
Nodes (20): addPasswordToggle(), bindForm(), clearError(), errorText(), field(), showFieldError(), validate(), ready (+12 more)

### Community 3 - "Архитектура и требования"
Cohesion: 0.13
Nodes (17): UniMarket database design, Point ledger and balance changes, Seven-table MySQL schema, Per-device change log, Plain HTML page structure, Project architecture, Router to API to backend flow, Responsive landing Grid and Flexbox (+9 more)

### Community 4 - "Настройка и администрирование"
Cohesion: 0.23
Nodes (9): db(), ask(), askUser(), deleteUser(), listUsers(), printTable(), resetPassword(), showUser() (+1 more)

### Community 5 - "Карточки и общие функции"
Cohesion: 0.27
Nodes (11): createList(), load(), api(), courseOptions(), fillSelects(), logout(), MENU, showStatus() (+3 more)

### Community 6 - "Карточки и избранное"
Cohesion: 0.32
Nodes (10): COLORS, favoriteButton(), renderCard(), points(), price(), rating(), buy(), buyBlock() (+2 more)

### Community 7 - "Кабинет и история баллов"
Cohesion: 0.24
Nodes (10): content, describe(), historyRow(), formatDate(), TYPES, fill(), icon(), materialRow() (+2 more)

### Community 8 - "Общие элементы интерфейса"
Cohesion: 0.36
Nodes (11): button(), downloadLink(), el(), fileSize(), openDialog(), render(), card(), list (+3 more)

### Community 10 - "Каталог и поиск"
Cohesion: 0.29
Nodes (6): form, list, saved, search(), total, plural()

### Community 11 - "Страницы маркетплейса"
Cohesion: 0.32
Nodes (8): Главная страница, Вход, Восстановление доступа, Регистрация, Трёхшаговая регистрация, Профиль автора, Каталог материалов, Страница материала

### Community 12 - "Лицензии шрифтов"
Cohesion: 0.50
Nodes (4): SIL Open Font License 1.1 terms, Golos Text SIL Open Font License, Onest SIL Open Font License, Rubik SIL Open Font License

## Knowledge Gaps
- **34 isolated node(s):** `Marketplace using internal points`, `Local setup instructions`, `Project architecture overview`, `Golos Text SIL Open Font License`, `Onest SIL Open Font License` (+29 more)
  These have ≤1 connection - possible missing edges or undocumented components. (Counts symbols only; 84 node(s) total have ≤1 connection when file, concept and rationale nodes are included.)
- **20 thin communities (<3 nodes) omitted from report** — run `graphify query` to explore isolated nodes.

## Suggested Questions
_Questions this graph is uniquely positioned to answer:_

- **Why does `ApiError` connect `Материалы и покупки` to `Кабинет и вход`, `Покупки и баллы`?**
  _High betweenness centrality (0.069) - this node is a cross-community bridge._
- **Are the 19 inferred relationships involving `ApiError` (e.g. with `{closure#1}()` and `{closure#1}()`) actually correct?**
  _`ApiError` has 19 INFERRED edges - model-reasoned connections that need verification._
- **What connects `Marketplace using internal points`, `Local setup instructions`, `Project architecture overview` to the rest of the system?**
  _34 weakly-connected nodes found - possible documentation gaps or missing edges._
- **Should `Материалы и покупки` be split into smaller, more focused modules?**
  _Cohesion score 0.09851551956815115 - nodes in this community are weakly interconnected._
- **Why does `db()` connect `Настройка и администрирование` to `Материалы и покупки`, `Кабинет и вход`, `Покупки и баллы`?**
  _High betweenness centrality (0.040) - this node is a cross-community bridge._
- **Are the 14 inferred relationships involving `{closure#1}()` (e.g. with `AuthHandler` and `.login()`) actually correct?**
  _`{closure#1}()` has 14 INFERRED edges - model-reasoned connections that need verification._
- **Should `Кабинет и вход` be split into smaller, more focused modules?**
  _Cohesion score 0.10227272727272728 - nodes in this community are weakly interconnected._