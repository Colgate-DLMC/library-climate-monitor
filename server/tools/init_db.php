<?php
// Create the tables from the command line (alternative to importing the .sql in phpMyAdmin).
//
//   php server/tools/init_db.php                  uses the normal config lookup
//   CLIMATE_CONFIG=/path/to/config.php php server/tools/init_db.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/../public/lib.php';

$file = __DIR__ . '/../' . (is_sqlite() ? 'schema.sqlite.sql' : 'schema.mysql.sql');
$sql  = file_get_contents($file);
$pdo  = db();
// Strip comment lines, then run each statement.
$sql = preg_replace('/^\s*--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
    $pdo->exec($statement);
}
fwrite(STDOUT, 'Schema applied from ' . basename($file) . PHP_EOL);
