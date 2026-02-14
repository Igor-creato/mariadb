# Development Directory

Эта папка содержит все файлы для разработки и не включается в production релиз.

## 📁 Структура

```
development/
├── config/                     # Конфигурационные файлы
│   ├── phpstan.neon           # PHPStan конфигурация
│   ├── phpstan-baseline.neon  # PHPStan baseline (65 некритичных предупреждений)
│   └── phpstan-bootstrap.php  # Bootstrap для WordPress функций
├── docs/                       # Документация
│   ├── README.md              # Полное описание плагина
│   ├── CHANGELOG.md           # История изменений
│   └── SECURITY.md            # Политика безопасности
├── composer.json              # Composer зависимости (копия)
├── composer.lock              # Composer lock (копия)
├── .editorconfig              # EditorConfig настройки
├── CONTRIBUTING.md            # Руководство для разработчиков
├── INSTALL_FILES.txt          # Список файлов для релиза
└── build-release.sh           # Скрипт сборки релиза
```

## 🔧 Команды для разработки

### Установка зависимостей

```bash
# Из корня плагина
composer install
```

### Проверка кода

```bash
# PHPStan (статический анализ)
composer run phpstan

# PHPCS (WordPress Coding Standards)
composer run phpcs

# Все проверки
composer run lint

# Автоисправление
composer run phpcbf
```

### Сборка релиза

```bash
# Из корня плагина
./development/build-release.sh

# Результат:
# → build/cashback-plugin-1.0.0.zip
# → build/cashback-plugin-1.0.0.zip.sha256
```

## 📝 Важно

1. **Файлы в `development/` НЕ включаются в релиз** - они нужны только для разработки
2. **`composer.json` дублируется** - копия в корне для работы Composer, оригинал здесь
3. **PHPStan конфигурация** - используется из `development/config/phpstan.neon`
4. **Документация** - полная версия в `development/docs/`, краткая в корне

## 🚫 Что НЕ добавлять в эту папку

- Production код (PHP файлы плагина)
- Assets (CSS/JS)
- Файлы перевода (languages/)
- Vendor зависимости (автоматически игнорируются)

## 📊 PHPStan

### Текущее состояние

- **Уровень:** 6
- **Статус:** ✅ Проходит с baseline
- **Baseline:** 65 некритичных предупреждений (отсутствующие типы в legacy коде)

### Обновление baseline

```bash
# Генерация нового baseline
composer run phpstan -- --generate-baseline=development/config/phpstan-baseline.neon
```

## 🎯 Цели

- Отделить файлы разработки от production кода
- Упростить сборку релиза
- Улучшить структуру проекта
- Сохранить обратную совместимость с инструментами (Composer, PHPStan)
