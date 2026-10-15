<?php
// relief.ccra.tw 資料庫連線（PostgreSQL，獨立資料庫 relief）。
// 連線資訊放主機 /etc/relief/db.ini（root:www-data 640，不進 repo）。
// 正式資料在 schema app；測試接縫：命令列且 RELIEF_TEST_DB=1 時改用 schema test，測試資料不進正式表。
const RELIEF_DB_INI = '/etc/relief/db.ini';

function rdb(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $schema = (PHP_SAPI === 'cli' && getenv('RELIEF_TEST_DB') === '1') ? 'test' : 'app';
    $ini = getenv('RELIEF_DB_INI') ?: RELIEF_DB_INI;
    $c = parse_ini_file($ini);
    if (!$c) throw new RuntimeException('db.ini 讀不到');
    $dsn = "pgsql:host={$c['host']};dbname={$c['dbname']}" . (isset($c['port']) ? ";port={$c['port']}" : '');
    $pdo = new PDO($dsn, $c['user'], $c['password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("CREATE SCHEMA IF NOT EXISTS $schema");
    $pdo->exec("SET search_path TO $schema");
    return $pdo;
}

function rdb_schema(): string {
    return (PHP_SAPI === 'cli' && getenv('RELIEF_TEST_DB') === '1') ? 'test' : 'app';
}
