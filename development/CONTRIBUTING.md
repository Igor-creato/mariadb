# Contributing to Cashback Plugin

Спасибо за ваш интерес к улучшению Cashback Plugin! Мы приветствуем вклад от сообщества.

## Как внести вклад

### Сообщение об ошибках

Перед созданием issue:

1. Проверьте, не была ли уже сообщена эта ошибка
2. Используйте последнюю версию плагина
3. Предоставьте подробную информацию:
   - Версия плагина
   - Версия WordPress
   - Версия WooCommerce
   - Версия PHP
   - Шаги для воспроизведения
   - Ожидаемое и фактическое поведение
   - Скриншоты (если применимо)
   - Логи ошибок

### Предложение новых функций

1. Создайте issue с тегом "enhancement"
2. Опишите:
   - Какую проблему решает функция
   - Как это должно работать
   - Примеры использования
3. Дождитесь обсуждения перед началом работы

### Pull Requests

1. **Fork** репозитория
2. **Создайте ветку** для вашей функции:
   ```bash
   git checkout -b feature/amazing-feature
   ```
3. **Внесите изменения** следуя Code Style
4. **Напишите тесты** (если применимо)
5. **Запустите линтеры**:
   ```bash
   composer run lint
   ```
6. **Коммит** с понятным сообщением:
   ```bash
   git commit -m "Add amazing feature"
   ```
7. **Push** в вашу ветку:
   ```bash
   git push origin feature/amazing-feature
   ```
8. **Создайте Pull Request**

## Code Style

### PHP

Следуем [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/php/):

```bash
# Проверка
composer run phpcs

# Автоисправление
composer run phpcbf
```

**Ключевые правила:**

- Отступы: **табы** (не пробелы)
- Открывающая скобка: на той же строке для функций, на новой для классов
- Именование:
  - Функции: `snake_case`
  - Классы: `PascalCase`
  - Константы: `UPPER_CASE`
  - Приватные методы/свойства: `snake_case` с префиксом подчеркивания (необязательно)

**Пример:**

```php
<?php

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

class My_Class
{
    private string $property;

    public function my_method(string $param): string
    {
        return esc_html($param);
    }
}
```

### JavaScript

Следуем WordPress JavaScript Coding Standards:

- Отступы: **табы**
- Точка с запятой: **обязательна**
- Кавычки: **одинарные** (''), кроме JSON

**Пример:**

```javascript
(function($) {
    'use strict';

    $(document).ready(function() {
        console.log('Hello World');
    });
})(jQuery);
```

### Комментарии

Используем PHPDoc для документации:

```php
/**
 * Краткое описание функции
 *
 * Длинное описание функции,
 * может быть многострочным.
 *
 * @param string $param Описание параметра
 * @return bool True при успехе, false при ошибке
 * @throws Exception Когда возникает ошибка
 */
public function my_function(string $param): bool
{
    // Код
}
```

## Требования к коду

### Безопасность

- ✅ Валидируйте **ВСЕ** входные данные
- ✅ Экранируйте **ВСЕ** выходные данные
- ✅ Используйте prepared statements для SQL
- ✅ Проверяйте права доступа (capabilities)
- ✅ Используйте nonces для форм и AJAX

**Плохо:**

```php
echo $_POST['name']; // ❌ XSS!
$wpdb->query("DELETE FROM table WHERE id = {$_GET['id']}"); // ❌ SQL Injection!
```

**Хорошо:**

```php
echo esc_html(sanitize_text_field($_POST['name'])); // ✅
$wpdb->delete('table', ['id' => absint($_GET['id'])], ['%d']); // ✅
```

### Производительность

- Избегайте запросов в циклах (N+1 problem)
- Используйте кеширование для дорогих операций
- Минимизируйте количество SQL запросов
- Используйте transients для временных данных

### Совместимость

- PHP 7.4+ (используем типизацию)
- WordPress 5.8+
- WooCommerce 5.0+
- Тестируйте с последними версиями

## Тестирование

### Ручное тестирование

1. Активируйте плагин на чистой установке WordPress + WooCommerce
2. Проверьте все функции:
   - Создание/обновление транзакций
   - Заявки на выплаты
   - Систему поддержки
   - Админ-панель
3. Проверьте на ошибки в логах

### Статический анализ

```bash
# PHPStan
composer run phpstan

# PHPCS
composer run phpcs
```

### Браузерное тестирование

Тестируйте в:
- Chrome (последняя версия)
- Firefox (последняя версия)
- Safari (последняя версия)
- Edge (последняя версия)

## Структура коммитов

Используем [Conventional Commits](https://www.conventionalcommits.org/):

```
<type>(<scope>): <subject>

<body>

<footer>
```

**Types:**

- `feat`: новая функция
- `fix`: исправление ошибки
- `docs`: изменения в документации
- `style`: форматирование, отсутствие изменений кода
- `refactor`: рефакторинг кода
- `perf`: улучшение производительности
- `test`: добавление тестов
- `chore`: изменения в сборке, зависимостях и т.д.

**Примеры:**

```bash
feat(support): add ticket priority filter in admin

fix(cashback): prevent duplicate transaction processing

docs(readme): update installation instructions

refactor(withdrawal): simplify payout validation logic
```

## Ветвление

- `main` - стабильная версия
- `develop` - разработка
- `feature/*` - новые функции
- `fix/*` - исправления ошибок
- `hotfix/*` - срочные исправления

## Checklist перед Pull Request

- [ ] Код следует WordPress Coding Standards
- [ ] PHPStan проходит без ошибок
- [ ] PHPCS проходит без ошибок
- [ ] Все функции протестированы вручную
- [ ] Обновлена документация (если нужно)
- [ ] Добавлена запись в CHANGELOG.md
- [ ] Коммиты имеют понятные сообщения
- [ ] PR имеет понятное описание

## Вопросы?

Если у вас есть вопросы, создайте issue или свяжитесь с нами.

## Лицензия

Внося вклад, вы соглашаетесь, что ваш код будет лицензирован под GPL v2 or later.

---

**Спасибо за ваш вклад! 🎉**
