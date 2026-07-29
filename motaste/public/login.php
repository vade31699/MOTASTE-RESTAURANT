<?php
// Simple login handler for MOTASTE staff login.
// This file can be used if you want to submit the login form to the server.

$allowedRoles = [
    'Admin' => [],
    'Cashier' => [],
    'Inventory Manager' => [],
];

function sanitize($value) {
    return htmlspecialchars(trim($value), ENT_QUOTES, 'UTF-8');
}

$role = isset($_POST['role']) ? sanitize($_POST['role']) : '';
$email = isset($_POST['email']) ? sanitize($_POST['email']) : '';
$password = isset($_POST['password']) ? sanitize($_POST['password']) : '';

$isValid = false;
if (isset($allowedRoles[$role]) && isset($allowedRoles[$role][$email])) {
    $isValid = $allowedRoles[$role][$email] === $password;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login Result</title>
    <style>
        body {
            margin: 0;
            font-family: Arial, sans-serif;
            background: #f5f5f5;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 20px;
        }
        .result-card {
            background: #fff;
            border-radius: 16px;
            box-shadow: 0 12px 30px rgba(0,0,0,0.12);
            padding: 32px;
            max-width: 420px;
            width: 100%;
            text-align: center;
        }
        .result-card h1 {
            margin-top: 0;
            font-size: 24px;
        }
        .result-card p {
            color: #555;
            line-height: 1.5;
        }
        .result-card a {
            display: inline-block;
            margin-top: 24px;
            padding: 12px 20px;
            background: #111;
            color: #fff;
            border-radius: 10px;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="result-card">
        <?php if ($isValid): ?>
            <h1>Login Successful</h1>
            <p>Welcome back, <strong><?php echo $role; ?></strong>.</p>
            <p>Your credentials were verified successfully.</p>
        <?php else: ?>
            <h1>Login Failed</h1>
            <p>Invalid email, password, or role. Please check your credentials and try again.</p>
        <?php endif; ?>
        <a href="staff.html">Return to Login</a>
    </div>
</body>
</html>
