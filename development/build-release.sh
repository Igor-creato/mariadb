#!/bin/bash

# Скрипт сборки релиза Cashback Plugin
# Создает чистую копию плагина без файлов разработки

set -e

VERSION="1.0.0"
PLUGIN_NAME="cashback-plugin"
BUILD_DIR="../build"
RELEASE_DIR="${BUILD_DIR}/${PLUGIN_NAME}"
ZIP_NAME="${PLUGIN_NAME}-${VERSION}.zip"

echo "🚀 Сборка релиза ${PLUGIN_NAME} v${VERSION}"
echo "================================================"

# Переход в корень плагина
cd "$(dirname "$0")/.."

# Очистка предыдущей сборки
if [ -d "${BUILD_DIR}" ]; then
    echo "🧹 Очистка предыдущей сборки..."
    rm -rf "${BUILD_DIR}"
fi

# Создание директории сборки
echo "📁 Создание директории сборки..."
mkdir -p "${RELEASE_DIR}"

# Копирование обязательных файлов
echo "📦 Копирование файлов плагина..."

# Корневые PHP файлы
cp cashback-plugin.php "${RELEASE_DIR}/"
cp mariadb.php "${RELEASE_DIR}/"
cp cashback-history.php "${RELEASE_DIR}/"
cp cashback-withdrawal.php "${RELEASE_DIR}/"
cp history-payout.php "${RELEASE_DIR}/"
cp wc-affiliate-url-params.php "${RELEASE_DIR}/"
cp uninstall.php "${RELEASE_DIR}/"

# Инструкция по установке → README.md для релиза
cp INSTALL.md "${RELEASE_DIR}/README.md"

# Документация из development/docs
cp development/docs/CHANGELOG.md "${RELEASE_DIR}/"
cp development/docs/SECURITY.md "${RELEASE_DIR}/"

# Папки
cp -r admin "${RELEASE_DIR}/"
cp -r support "${RELEASE_DIR}/"
cp -r assets "${RELEASE_DIR}/"
cp -r languages "${RELEASE_DIR}/"

# Очистка ненужных файлов в скопированных папках
echo "🧹 Очистка временных файлов..."
find "${RELEASE_DIR}" -name ".DS_Store" -delete 2>/dev/null || true
find "${RELEASE_DIR}" -name "Thumbs.db" -delete 2>/dev/null || true
find "${RELEASE_DIR}" -name "*.swp" -delete 2>/dev/null || true
find "${RELEASE_DIR}" -name "*.bak" -delete 2>/dev/null || true
find "${RELEASE_DIR}" -name "*.tmp" -delete 2>/dev/null || true
find "${RELEASE_DIR}" -name "*~" -delete 2>/dev/null || true

# Подсчет файлов
FILE_COUNT=$(find "${RELEASE_DIR}" -type f | wc -l)
DIR_SIZE=$(du -sh "${RELEASE_DIR}" 2>/dev/null | cut -f1 || echo "N/A")

echo "✅ Скопировано файлов: ${FILE_COUNT}"
echo "📊 Размер: ${DIR_SIZE}"

# Создание ZIP архива
echo "🗜️  Создание ZIP архива..."
cd "${BUILD_DIR}"
zip -r "${ZIP_NAME}" "${PLUGIN_NAME}" -q
cd ..

ZIP_SIZE=$(du -sh "${BUILD_DIR}/${ZIP_NAME}" 2>/dev/null | cut -f1 || echo "N/A")
echo "✅ ZIP создан: ${BUILD_DIR}/${ZIP_NAME} (${ZIP_SIZE})"

# Создание контрольной суммы
echo "🔐 Создание контрольной суммы..."
cd "${BUILD_DIR}"
sha256sum "${ZIP_NAME}" > "${ZIP_NAME}.sha256" 2>/dev/null || \
    shasum -a 256 "${ZIP_NAME}" > "${ZIP_NAME}.sha256"
cd ..

echo ""
echo "================================================"
echo "✅ Релиз готов!"
echo "================================================"
echo "📦 Архив: ${BUILD_DIR}/${ZIP_NAME}"
echo "🔐 SHA256: ${BUILD_DIR}/${ZIP_NAME}.sha256"
echo ""
echo "📋 Установка:"
echo "   1. Загрузите ${ZIP_NAME} в WordPress Admin"
echo "   2. Перейдите в Плагины → Добавить новый → Загрузить плагин"
echo "   3. Выберите ZIP файл и нажмите Установить"
echo "   4. Активируйте плагин"
echo ""
echo "🎉 Готово!"
