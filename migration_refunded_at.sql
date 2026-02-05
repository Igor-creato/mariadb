-- Миграция для добавления колонки refunded_at и триггеров защиты failed-статуса
-- Дата создания: 2026-02-05
-- Описание: Добавляет поддержку возврата средств при failed-статусе

-- =====================================================
-- ШАГ 1: Добавление колонки refunded_at
-- =====================================================

-- Проверяем существование колонки перед добавлением
ALTER TABLE `wp_cashback_payout_requests`
ADD COLUMN IF NOT EXISTS `refunded_at` datetime DEFAULT NULL COMMENT 'Время возврата средств после failed-статуса'
AFTER `status`;

-- =====================================================
-- ШАГ 2: Добавление индекса для колонки refunded_at
-- =====================================================

-- Создаём индекс для быстрого поиска возвращённых заявок
ALTER TABLE `wp_cashback_payout_requests`
ADD INDEX IF NOT EXISTS `idx_refunded` (`refunded_at`);

-- =====================================================
-- ШАГ 3: Создание триггеров защиты failed-статуса
-- =====================================================

-- Удаляем существующие триггеры если они есть
DROP TRIGGER IF EXISTS `wp_tr_prevent_delete_failed_payout`;
DROP TRIGGER IF EXISTS `wp_tr_prevent_update_failed_payout`;

-- Триггер защиты от удаления заявок со статусом 'failed'
DELIMITER $$
CREATE TRIGGER `wp_tr_prevent_delete_failed_payout`
BEFORE DELETE ON `wp_cashback_payout_requests`
FOR EACH ROW
-- 'Запрещает удаление заявок на выплату со статусом ''failed'' (возвращено в баланс)'
BEGIN
    IF OLD.status = 'failed' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Удаление запрещено: заявка со статусом failed не может быть удалена.';
    END IF;
END$$
DELIMITER ;

-- Триггер защиты от изменения заявок со статусом 'failed'
DELIMITER $$
CREATE TRIGGER `wp_tr_prevent_update_failed_payout`
BEFORE UPDATE ON `wp_cashback_payout_requests`
FOR EACH ROW
-- 'Запрещает изменение заявок на выплату со статусом ''failed'' (возвращено в баланс)'
BEGIN
    IF OLD.status = 'failed' THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Изменение запрещено: заявка со статусом failed не может быть изменена.';
    END IF;
END$$
DELIMITER ;

-- =====================================================
-- ШАГ 4: Проверка выполнения миграции
-- =====================================================

-- Проверяем что колонка добавлена
SELECT COUNT(*) AS column_exists 
FROM INFORMATION_SCHEMA.COLUMNS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'wp_cashback_payout_requests' 
  AND COLUMN_NAME = 'refunded_at';

-- Проверяем что индекс создан
SELECT COUNT(*) AS index_exists 
FROM INFORMATION_SCHEMA.STATISTICS 
WHERE TABLE_SCHEMA = DATABASE() 
  AND TABLE_NAME = 'wp_cashback_payout_requests' 
  AND INDEX_NAME = 'idx_refunded';

-- Проверяем что триггеры созданы
SELECT TRIGGER_NAME, EVENT_MANIPULATION, EVENT_OBJECT_TABLE 
FROM INFORMATION_SCHEMA.TRIGGERS 
WHERE TRIGGER_SCHEMA = DATABASE() 
  AND TRIGGER_NAME IN ('wp_tr_prevent_delete_failed_payout', 'wp_tr_prevent_update_failed_payout');

-- =====================================================
-- ПРИМЕЧАНИЯ
-- =====================================================
-- 1. Замените префикс таблицы 'wp_' на ваш префикс WordPress если он отличается
-- 2. Этот скрипт безопасно выполнять повторно (использует IF NOT EXISTS)
-- 3. Триггеры автоматически пересоздаются при повторном запуске
-- 4. После выполнения миграции необходимо деактивировать и активировать плагин
--    для обновления структуры таблицы через dbDelta()
