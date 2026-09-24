<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Forgot Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    {{-- Shared by all three password screens; see public/css/auth.css. --}}
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v=20260924a">
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

                <div class="auth-field">
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

                <div class="auth-field">
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

            {{-- Recovery belongs to the staff portal, so the page leads back to the staff login. --}}
            <a href="{{ route('staff') }}" class="back-link">&larr; Back to staff login</a>
        @endif
    </div>

    <script src="{{ asset('js/auth-form-submit.js') }}"></script>
</body>
</html>