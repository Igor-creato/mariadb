<?php

/**
 * Класс для добавления полей способа вывода и аккаунта в профиль пользователя
 * 
 * ПРИМЕЧАНИЕ: Функциональность перенесена на страницу вывода кэшбэка.
 * Этот класс оставлен для обратной совместимости.
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

class CashbackUserProfileFields
{
    /**
     * Конструктор класса
     */
    public function __construct()
    {
        // Функциональность перенесена на страницу вывода кэшбэка (cashback-withdrawal.php)
        // Класс оставлен для обратной совместимости
    }
}

// Инициализация класса
new CashbackUserProfileFields();
