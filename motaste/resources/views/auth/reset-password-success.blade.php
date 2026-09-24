<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset Successful</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    {{-- Shared by all three password screens; see public/css/auth.css. --}}
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v=20260924a">
</head>
<body>
    <div class="card card-centered">
        <div class="icon">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
            </svg>
        </div>

        <h1>Successfully reset password</h1>
        <p class="success-text">Your password has been reset.</p>

        <a href="https://motaste.laravel.cloud" class="btn-success">
            Proceed to motaste.laravel.cloud
        </a>
    </div>
</body>
</html>
