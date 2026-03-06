<?php
$m = new mysqli('localhost', 'root', '', 'kash-back', 3307);
$r = $m->query("SELECT option_value FROM wp_options WHERE option_name = 'rewrite_rules'");
$row = $r->fetch_assoc();
$rules = unserialize($row['option_value']);
if (!$rules) {
    echo "REWRITE RULES EMPTY OR FALSE\n";
} else {
    $found = false;
    foreach ($rules as $p => $rl) {
        if (stripos($p, 'cashback') !== false || stripos($rl, 'cashback') !== false) {
            echo $p . ' => ' . $rl . "\n";
            $found = true;
        }
    }
    if (!$found) echo 'No cashback rules found. Total rules: ' . count($rules) . "\n";
}
$m->close();
