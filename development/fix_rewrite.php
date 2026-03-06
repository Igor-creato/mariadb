<?php
// Одноразовый скрипт для установки transient
$m = new mysqli('localhost', 'root', '', 'kash-back', 3307);

// Проверяем, есть ли колонка bank_required
$r = $m->query("SELECT COUNT(*) as cnt FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'kash-back' AND TABLE_NAME = 'wp_cashback_payout_methods' AND COLUMN_NAME = 'bank_required'");
$row = $r->fetch_assoc();
echo "bank_required column exists: " . ($row['cnt'] > 0 ? 'YES' : 'NO') . "\n";

if ($row['cnt'] == 0) {
    $m->query("ALTER TABLE `wp_cashback_payout_methods` ADD COLUMN `bank_required` tinyint(1) NOT NULL DEFAULT 1 COMMENT '1 = для этого способа нужно выбрать банк' AFTER `sort_order`");
    echo "Column added: " . ($m->error ?: 'OK') . "\n";
}

// Устанавливаем transient для flush rewrite rules
$expiration = time() + 300;
$m->query("DELETE FROM wp_options WHERE option_name IN ('_transient_cashback_flush_rewrite_rules', '_transient_timeout_cashback_flush_rewrite_rules')");
$m->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('_transient_cashback_flush_rewrite_rules', '1', 'yes')");
$m->query("INSERT INTO wp_options (option_name, option_value, autoload) VALUES ('_transient_timeout_cashback_flush_rewrite_rules', '$expiration', 'yes')");
echo "Transient set for flush_rewrite_rules\n";

// Проверяем db_version
$r = $m->query("SELECT option_value FROM wp_options WHERE option_name = 'cashback_plugin_db_version'");
$row = $r->fetch_assoc();
echo "DB version: " . ($row ? $row['option_value'] : 'not set') . "\n";

$m->close();
echo "Done. Now reload any page in the browser.\n";
