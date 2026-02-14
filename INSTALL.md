# Cashback Plugin - Quick Start

Краткая инструкция по установке и использованию плагина.

## 📦 Установка

### Для пользователей

1. Загрузите ZIP файл плагина
2. WordPress Admin → Плагины → Добавить новый → Загрузить
3. Активируйте плагин

### Для разработчиков

```bash
# 1. Установите зависимости
cd development
composer install

# 2. Проверьте код
composer run lint

# 3. Соберите релиз
./build-release.sh
```

## 📚 Документация

Полная документация находится в [`development/docs/`](development/docs/):

- [README.md](development/docs/README.md) - Подробное описание
- [SECURITY.md](development/docs/SECURITY.md) - Безопасность
- [CHANGELOG.md](development/docs/CHANGELOG.md) - История изменений
- [CONTRIBUTING.md](development/CONTRIBUTING.md) - Для разработчиков

## ⚙️ Требования

- PHP 7.4+
- WordPress 6.2+
- WooCommerce 5.0+
- MySQL 5.7+ / MariaDB 10.2+

## 🔧 Основные команды

```bash
# Из папки development/
composer run phpstan  # PHPStan анализ
composer run phpcs    # Code standards
composer run lint     # Все проверки
composer run fix      # Автоисправление

# Сборка релиза
./development/build-release.sh
```

## 📞 Поддержка

- Email: security@example.com
- Issues: GitHub Issues

---

**Версия:** 1.0.0 | **Лицензия:** GPL v2+
