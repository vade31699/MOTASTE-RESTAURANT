<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Successful</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;
            background-color: #f3f4f6;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .card {
            width: 100%;
            max-width: 420px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 40px 32px;
            text-align: center;
        }
        .icon {
            width: 56px;
            height: 56px;
            margin: 0 auto 20px;
            background: #dcfce7;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .icon svg { width: 28px; height: 28px; color: #16a34a; }
        h1 {
            font-size: 22px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
        }
        .success-text {
            color: #16a34a;
            font-weight: 600;
            font-size: 15px;
            margin-bottom: 20px;
        }
        .btn {
            display: inline-block;
            padding: 12px 24px;
            background: #16a34a;
            color: #fff;
            text-decoration: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            transition: background 0.2s;
        }
        .btn:hover { background: #15803d; }
        .link {
            display: block;
            margin-top: 12px;
            color: #6b7280;
            font-size: 14px;
            text-decoration: none;
        }
        .link:hover { color: #374151; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="icon">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>

        <h1>Successfully reset password</h1>
        <p class="success-text">Your password has been reset.</p>

        <a href="https://motaste.laravel.cloud" class="btn">
            Proceed to motaste.laravel.cloud
        </a>
    </div>
</body>
</html>
