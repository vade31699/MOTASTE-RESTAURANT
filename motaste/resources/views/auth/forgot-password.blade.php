<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
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
            padding: 36px 32px;
        }
        .logo {
            width: 56px;
            height: 56px;
            margin: 0 auto 16px;
            background: #1e293b;
            border-radius: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logo svg { width: 32px; height: 32px; }
        h1 { text-align: center; font-size: 22px; font-weight: 700; color: #111827; margin-bottom: 8px; }
        .description { text-align: center; color: #6b7280; font-size: 14px; line-height: 1.5; margin-bottom: 24px; }
        .status { background: #dcfce7; color: #16a34a; padding: 10px 14px; border-radius: 8px; font-size: 14px; font-weight: 500; margin-bottom: 16px; text-align: center; }
        .label { display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px; }
        .input { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 15px; color: #1f2937; outline: none; }
        .input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
        .error { color: #dc2626; font-size: 13px; margin-top: 4px; }
        .btn { width: 100%; padding: 12px; background: #1e293b; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; text-transform: uppercase; cursor: pointer; }
        .btn:hover { background: #0f172a; }
        .btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #6b7280; font-size: 14px; text-decoration: none; }
        .back-link:hover { color: #374151; text-decoration: underline; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 015.656 0l4-4a4 4 0 01-5.656-5.656l-1.1 1.1" />
            </svg>
        </div>

        <h1>Forgot Password</h1>
        <p class="description">No problem. Just let us know your email address and we will email you a password reset link.</p>

        <?php if (session('status')): ?>
            <div class="status"><?php echo e(session('status')); ?></div>
        <?php endif; ?>

        <form method="POST" action="<?php echo e(route('password.email')); ?>">
            <?php echo csrf_field(); ?>

            <div class="mb-4">
                <label class="label" for="email">Email</label>
                <input
                    id="email"
                    type="email"
                    class="input"
                    name="email"
                    value="<?php echo e(old('email')); ?>"
                    required
                    autofocus
                    autocomplete="email"
                >
                <?php if($errors->has('email')): ?>
                    <div class="error"><?php echo e($errors->first('email')); ?></div>
                <?php endif; ?>
            </div>

            <button type="submit" class="btn" id="sendBtn">Email Password Reset Link</button>
        </form>

        <a href="<?php echo e(route('login')); ?>" class="back-link">&larr; Back to login</a>
    </div>

    <script>
        var form = document.querySelector('form');
        var btn = document.getElementById('sendBtn');
        form.addEventListener('submit', function () {
            btn.disabled = true;
            btn.textContent = 'Sending...';
        });
    </script>
</body>
</html>
