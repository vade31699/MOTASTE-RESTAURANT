<?php
$tests = [
    ['root', ''],
    ['root', 'motaste'],
];
foreach ($tests as $test) {
    $user = $test[0];
    $pass = $test[1];
    $mysqli = new mysqli('127.0.0.1', $user, $pass, 'motaste_db');
    echo "user={$user} pass=" . ($pass === '' ? '(blank)' : $pass) . ': ';
    if ($mysqli->connect_error) {
        echo "fail: {$mysqli->connect_error}\n";
    } else {
        echo "OK\n";
    }
}
