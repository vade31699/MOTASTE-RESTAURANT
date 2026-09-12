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
        .description strong { color: #374151; }
        .status { background: #dcfce7; color: #16a34a; padding: 10px 14px; border-radius: 8px; font-size: 14px; font-weight: 500; margin-bottom: 16px; text-align: center; }
        .label { display: block; font-size: 14px; font-weight: 500; color: #374151; margin-bottom: 6px; }
        .input { width: 100%; padding: 10px 14px; border: 1px solid #d1d5db; border-radius: 8px; font-size: 15px; color: #1f2937; outline: none; }
        .input:focus { border-color: #6366f1; box-shadow: 0 0 0 3px rgba(99,102,241,0.1); }
        .error { color: #dc2626; font-size: 13px; margin-top: 4px; }
        .btn { width: 100%; padding: 12px; background: #1e293b; color: #fff; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; text-transform: uppercase; cursor: pointer; }
        .btn:hover { background: #0f172a; }
        .btn:disabled { background: #94a3b8; cursor: not-allowed; }
        .btn-row { display: flex; gap: 12px; margin-top: 12px; }
        .btn-row form { flex: 1; }
        .btn-secondary { width: 100%; padding: 10px; background: #fff; color: #374151; border: 1px solid #d1d5db; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
        .btn-secondary:hover { background: #f3f4f6; }
        .back-link { display: block; text-align: center; margin-top: 20px; color: #6b7280; font-size: 14px; text-decoration: none; }
        .back-link:hover { color: #374151; text-decoration: underline; }
        .mb-4 { margin-bottom: 16px; }
    </style>
</head>
<body>
    <div class="card">
        <div class="logo">
            <svg fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                <path stroke-linecap="round" stroke-linejoin="round" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 015.656 0l4-4a4 4 0 01-5.656-5.656l-1.1 1.1" />
            </svg>
        </div>

        @if(session('status'))
            <h1>Check Your Email</h1>
            <p class="description">{{ session('status') }}</p>
        @elseif($pendingEmail)
            <h1>Enter Verification Code</h1>
            <p class="description">
                We emailed a 6-digit verification code to <strong>{{ $pendingEmail }}</strong>.
                Enter it below — once it is confirmed you can reset your password right away.
            </p>

            @if(session('codeSent'))
                <div class="status">Code sent. If you haven't received it yet, check your spam folder.</div>
            @endif

            <form method="POST" action="{{ route('password.verify') }}">
                @csrf
                <input type="hidden" name="email" value="{{ $pendingEmail }}">

                <div class="mb-4">
                    <label class="label" for="code">Verification Code</label>
                    <input
                        id="code"
                        type="text"
                        inputmode="numeric"
                        pattern="[0-9]*"
                        maxlength="6"
                        class="input"
                        name="code"
                        placeholder="6-digit code"
                        required
                        autofocus
                        autocomplete="one-time-code"
                    >
                    @error('code')
                        <div class="error">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn" id="verifyBtn">Verify Code</button>
            </form>

            <div class="btn-row">
                <form method="POST" action="{{ route('password.email') }}">
                    @csrf
                    <input type="hidden" name="email" value="{{ $pendingEmail }}">
                    <button type="submit" class="btn-secondary">Resend Code</button>
                </form>

                <form method="POST" action="{{ route('password.request.cancel') }}">
                    @csrf
                    <button type="submit" class="btn-secondary">Use a Different Email</button>
                </form>
            </div>
        @else
            <h1>Forgot Password</h1>
            <p class="description">
                No problem. Enter your email address and we will send you a verification
                code to confirm your identity before you can reset your password.
            </p>

            <form method="POST" action="{{ route('password.email') }}">
                @csrf

                <div class="mb-4">
                    <label class="label" for="email">Email</label>
                    <input
                        id="email"
                        type="email"
                        class="input"
                        name="email"
                        value="{{ old('email') }}"
                        required
                        autofocus
                        autocomplete="email"
                    >
                    @error('email')
                        <div class="error">{{ $message }}</div>
                    @enderror
                </div>

                <button type="submit" class="btn" id="sendBtn" data-loading-text="Please wait...">Send Verification Code</button>
            </form>

            <a href="{{ route('login') }}" class="back-link">&larr; Back to login</a>
        @endif
    </div>

    <script src="{{ asset('js/auth-form-submit.js') }}"></script>
</body>
</html>