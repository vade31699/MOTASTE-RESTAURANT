<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $pageTitle ?? 'Staff Login' }} · MOTASTE</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@600;700;800&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('admin.css') }}">
</head>
<body class="admin-login-body">
    <div class="login-ambient" aria-hidden="true"></div>

    <main class="login-shell">
        <div class="login-brand">
            <span class="login-monogram">MT<span class="login-dot"></span></span>
            <span class="login-wordmark">Motaste Admin</span>
        </div>

        <div class="login-card">
            <!-- Step 1: credentials -->
            <div id="loginStep" class="login-step">
                <h1>Staff Login</h1>
                <p class="login-sub">Sign in to manage orders, inventory and more.</p>

                <form id="loginForm" novalidate>
                    <div class="field">
                        <label for="loginEmail">Email</label>
                        <input type="email" id="loginEmail" name="email" placeholder="you@example.com" autocomplete="username" required>
                    </div>
                    <div class="field">
                        <label for="loginPassword">Password</label>
                        <div class="password-wrap">
                            <input type="password" id="loginPassword" name="password" placeholder="••••••••" autocomplete="current-password" required>
                            <button type="button" class="password-toggle" data-toggle="loginPassword" aria-label="Show password">
                                <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                            </button>
                        </div>
                    </div>
                    <div class="field">
                        <label for="loginRole">Role</label>
                        <select id="loginRole">
                            <option value="">Select your role</option>
                            <option value="Admin">Admin</option>
                            <option value="Cashier">Cashier</option>
                            <option value="Inventory Manager">Inventory Manager</option>
                        </select>
                    </div>
                    <button type="submit" class="btn-primary btn-block" id="loginSubmitBtn">
                        <span class="btn-label">Sign In</span>
                        <span class="btn-spinner" hidden></span>
                    </button>
                    <p class="form-message" id="loginMessage" role="alert"></p>
                </form>
            </div>

            <!-- Step 2: device verification -->
            <div id="verifyStep" class="login-step" hidden>
                <h1>Verify this device</h1>
                <p class="login-sub" id="verifyHint">We emailed a 6-digit code to your inbox. Enter it below to trust this device.</p>

                <form id="verifyForm" novalidate>
                    <div class="field">
                        <label for="verifyCode">Verification code</label>
                        <input type="text" id="verifyCode" inputmode="numeric" maxlength="8" placeholder="6-digit code" autocomplete="one-time-code" required>
                    </div>
                    <button type="submit" class="btn-primary btn-block" id="verifySubmitBtn">
                        <span class="btn-label">Verify &amp; Sign In</span>
                        <span class="btn-spinner" hidden></span>
                    </button>
                    <button type="button" class="btn-ghost btn-block" id="verifyBackBtn">Back to login</button>
                    <p class="form-message" id="verifyMessage" role="alert"></p>
                </form>
            </div>
        </div>

        <p class="login-footer">MOTASTE Restaurant · Staff Portal</p>
    </main>

    <script src="{{ asset('admin.js') }}"></script>
</body>
</html>
