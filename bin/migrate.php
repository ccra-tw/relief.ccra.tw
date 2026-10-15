<?php
// schema 版本管理：依檔名順序套用 sql/NNN_*.sql，已套用的記在 schema_migrations。
// 用法：php bin/migrate.php            （正式 schema app）
//       RELIEF_TEST_DB=1 php bin/migrate.php （測試 schema test）
require __DIR__ . '/../lib/db.php';
$db = rdb();
$db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (name text PRIMARY KEY, applied_at timestamptz NOT NULL DEFAULT now())');
$done = $db->query('SELECT name FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
$files = glob(__DIR__ . '/../sql/*.sql');
sort($files);
$n = 0;
foreach ($files as $f) {
    $name = basename($f);
    if (in_array($name, $done, true)) continue;
    $db->beginTransaction();
    try {
        $db->exec(file_get_contents($f));
        $db->prepare('INSERT INTO schema_migrations (name) VALUES (?)')->execute([$name]);
        $db->commit();
        echo "applied $name (" . rdb_schema() . ")\n";
        $n++;
    } catch (Throwable $e) {
        $db->rollBack();
        fwrite(STDERR, "FAILED $name: " . $e->getMessage() . "\n");
        exit(1);
    }
}
echo "migrations: $n applied, schema " . rdb_schema() . "\n";
