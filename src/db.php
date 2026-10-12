<?php
function getPDO(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $file = __DIR__ . '/config.php';
        if (!is_file($file)) {
            throw new RuntimeException('Copy src/config.sample.php to src/config.php');
        }
        $c = require $file;
        $dsn = "mysql:host={$c['host']};dbname={$c['dbname']};charset=utf8mb4";
        $pdo = new PDO($dsn, $c['user'], $c['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }
    return $pdo;
}
