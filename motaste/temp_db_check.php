<?php
try {
    $db = new PDO('sqlite:' . __DIR__ . '/motaste_db');
    $stmt = $db->query('PRAGMA table_info(staff)');
    echo "STAFF TABLE SCHEMA:\n";
    while ($col = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo $col['cid'] . ': ' . $col['name'] . ' (' . $col['type'] . ')\n';
    }
    echo "---\n";
    $count = $db->query('SELECT COUNT(*) as c FROM staff')->fetch(PDO::FETCH_ASSOC);
    echo 'ROW COUNT: ' . $count['c'] . "\n";
    echo "---\n";
    $rows = $db->query('SELECT id, role, email, created_at, updated_at FROM staff LIMIT 50');
    foreach ($rows as $row) {
        echo $row['id'] . ' | ' . $row['role'] . ' | ' . $row['email'] . ' | ' . $row['created_at'] . ' | ' . $row['updated_at'] . "\n";
    }
} catch (Exception $e) {
    echo 'ERR: ' . $e->getMessage() . "\n";
}
?>
