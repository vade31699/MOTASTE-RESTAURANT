<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">
    {{-- Shared by all three password screens; see public/css/auth.css. --}}
    <link rel="stylesheet" href="{{ asset('css/auth.css') }}?v=20260924a">
</head>
<body>
    <div class="card card-condensed">
        <form method="POST" action="{{ route('password.store') }}">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email ?? old('email') }}">

            @error('email')
                <div class="error">{{ $message }}</div>
            @enderror

            <div class="auth-field">
                <label class="label" for="password">Password</label>
                <input
                    id="password"
                    type="password"
                    class="input"
                    name="password"
                    required
                    autofocus
                    autocomplete="new-password"
                >
                @error('password')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="auth-field-last">
                <label class="label" for="password_confirmation">Confirm Password</label>
                <input
                    id="password_confirmation"
                    type="password"
                    class="input"
                    name="password_confirmation"
                    required
                    autocomplete="new-password"
                >
                @error('password_confirmation')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit" class="btn" id="resetBtn" data-loading-text="Resetting...">Reset Password</button>
        </form>
    </div>

    <script src="{{ asset('js/auth-form-submit.js') }}"></script>
</body>
</html>
