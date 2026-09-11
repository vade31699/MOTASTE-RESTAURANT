<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetCode;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PasswordResetLinkController extends Controller
{
    private const CODE_TTL_MINUTES = 3;
    private const CODE_RESEND_WINDOW_SECONDS = 60;
    private const CODE_MAX_ATTEMPTS = 3;

    /**
     * Display the password reset request view.
     *
     * When a verification code is pending for an email (session), the view
     * shows the code-entry step instead of the email form.
     */
    public function create(): View
    {
        return view('auth.forgot-password', [
            'status' => session('status'),
            'pendingEmail' => session('password_reset_email'),
        ]);
    }

    /**
     * Step 1: email a 6-digit verification code to the account. The reset form
     * itself is deliberately NOT opened here — it is only shown after the code
     * has been confirmed (see verify()).
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->email));

        if (!DB::table('users')->whereRaw('LOWER(email) = ?', [$email])->exists()) {
            throw ValidationException::withMessages([
                'email' => ['We could not find an account with that email address.'],
            ]);
        }

        $this->ensurePasswordResetCodesTable();

        // Reuse a code issued within the last minute instead of emailing a
        // fresh one on every submit (mirrors the device-login flow).
        $recent = DB::table('password_reset_codes')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('expires_at', '>', now()->toDateTimeString())
            ->orderBy('id', 'desc')
            ->first();

        if ($recent && now()->diffInSeconds($recent->created_at) < self::CODE_RESEND_WINDOW_SECONDS) {
            session(['password_reset_email' => $email]);

            return back()->with('codeSent', true);
        }

        $code = $this->createPasswordResetCode($email);

        Mail::to($email)->send(new PasswordResetCode(
            $code,
            $email,
            now()->addMinutes(self::CODE_TTL_MINUTES)->toDateTimeString()
        ));

        session(['password_reset_email' => $email]);

        return back()->with('codeSent', true);
    }

    /**
     * Step 2: confirm the emailed code, then take the user straight to the
     * reset-password form. No second email with a link is sent — the reset
     * token is handed to the already-verified browser directly. The code is
     * single-use and self-destructs after 3 failed attempts.
     *
     * @throws ValidationException
     */
    public function verify(Request $request): RedirectResponse
    {
        $request->validate([
            'email' => 'required|email',
            'code' => 'required|string',
        ]);

        // The code is bound to the email it was sent to; a mid-flow email
        // change must not switch which account is verified.
        $pendingEmail = strtolower(trim((string)session('password_reset_email', '')));
        $email = $pendingEmail !== '' ? $pendingEmail : strtolower(trim($request->email));

        if (!$this->verifyPasswordResetCode($email, trim((string)$request->code))) {
            throw ValidationException::withMessages([
                'code' => ['Invalid or expired verification code.'],
            ]);
        }

        // Code confirmed — create a reset token and go straight to the form.
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            throw ValidationException::withMessages([
                'email' => ['We could not find an account with that email address.'],
            ]);
        }

        $token = Password::broker()->createToken($user);

        session()->forget('password_reset_email');

        return redirect()->route('password.reset', ['token' => $token, 'email' => $email]);
    }

    /**
     * Abandon a pending verification (wrong email, changed mind, etc.) and
     * return to the start of the forgot-password flow.
     */
    public function cancel(Request $request): RedirectResponse
    {
        $this->ensurePasswordResetCodesTable();

        $email = strtolower(trim((string)session('password_reset_email', '')));
        if ($email !== '') {
            DB::table('password_reset_codes')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->delete();
        }
        session()->forget('password_reset_email');

        return redirect()->route('password.request');
    }

    private function ensurePasswordResetCodesTable(): void
    {
        // At most once per request — avoids redundant introspection round-trips.
        static $verified = false;
        if ($verified) {
            return;
        }

        if (!Schema::hasTable('password_reset_codes')) {
            Schema::create('password_reset_codes', function ($table) {
                $table->id();
                $table->string('email', 191);
                // SHA-256 hex hash of the 6-digit code (never stored in plaintext).
                $table->string('code_hash', 64);
                $table->unsignedTinyInteger('attempts')->default(0);
                $table->timestamp('expires_at');
                $table->timestamps();

                $table->index('email', 'password_reset_codes_email_idx');
            });
        }

        $verified = true;
    }

    private function createPasswordResetCode(string $email): string
    {
        $this->ensurePasswordResetCodesTable();

        $email = strtolower(trim($email));
        $code = '';
        for ($i = 0; $i < 6; $i += 1) {
            $code .= (string)random_int(0, 9);
        }

        DB::table('password_reset_codes')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->delete();

        DB::table('password_reset_codes')->insert([
            'email' => $email,
            'code_hash' => hash('sha256', $code),
            'attempts' => 0,
            'expires_at' => now()->addMinutes(self::CODE_TTL_MINUTES)->toDateTimeString(),
            'created_at' => now()->toDateTimeString(),
            'updated_at' => now()->toDateTimeString(),
        ]);

        return $code;
    }

    private function verifyPasswordResetCode(string $email, string $code): bool
    {
        $this->ensurePasswordResetCodesTable();

        $email = strtolower(trim($email));
        $token = DB::table('password_reset_codes')
            ->whereRaw('LOWER(email) = ?', [$email])
            ->orderBy('id', 'desc')
            ->first();

        if (!$token) {
            return false;
        }

        if (now()->greaterThan($token->expires_at)) {
            DB::table('password_reset_codes')->where('id', $token->id)->delete();
            return false;
        }

        $hashedCode = hash('sha256', trim($code));

        // Claim the token atomically: the DELETE only removes the row when the
        // stored hash still matches, so a verified code can never be replayed.
        $claimed = DB::table('password_reset_codes')
            ->where('id', $token->id)
            ->where('code_hash', $hashedCode)
            ->delete();

        if ($claimed > 0) {
            return true;
        }

        // Wrong code: count the failure atomically and self-destruct the token
        // after 3 failed attempts so codes cannot be brute-forced.
        $affected = DB::table('password_reset_codes')
            ->where('id', $token->id)
            ->increment('attempts');

        if ($affected > 0) {
            $attempts = (int)DB::table('password_reset_codes')
                ->where('id', $token->id)
                ->value('attempts');
            if ($attempts >= self::CODE_MAX_ATTEMPTS) {
                DB::table('password_reset_codes')->where('id', $token->id)->delete();
            }
        }

        return false;
    }
}