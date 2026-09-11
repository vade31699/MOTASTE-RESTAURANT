<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
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
            max-width: 400px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            padding: 32px;
        }
        .label {
            display: block;
            font-size: 14px;
            font-weight: 500;
            color: #374151;
            margin-bottom: 6px;
        }
        .input {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #d1d5db;
            border-radius: 8px;
            font-size: 15px;
            color: #1f2937;
            outline: none;
            transition: border-color 0.2s;
        }
        .input:focus {
            border-color: #6366f1;
            box-shadow: 0 0 0 3px rgba(99,102,241,0.1);
        }
        .error {
            color: #dc2626;
            font-size: 13px;
            margin-top: 4px;
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #1e293b;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            text-transform: uppercase;
            cursor: pointer;
            transition: background 0.2s;
        }
        .btn:hover { background: #0f172a; }
        .btn:disabled { background: #94a3b8; cursor: not-allowed; }
    </style>
</head>
<body>
    <div class="card">
        <form method="POST" action="{{ route('password.store') }}">
            @csrf

            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $email ?? old('email') }}">

            @error('email')
                <div class="error">{{ $message }}</div>
            @enderror

            <div class="mb-4">
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

            <div class="mb-6">
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
