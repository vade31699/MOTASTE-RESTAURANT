<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AdminResetAttempt;
use App\Mail\PasswordResetCode;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class PasswordResetLinkController extends Controller
{
    /**
     * Shown when the entered address is the Admin account, which this staff-only
     * flow can never reset. Kept identical to the message the write step uses.
     */
    public const ADMIN_RECOVERY_MESSAGE = 'This is the admin account, which cannot be reset here. Staff password recovery works for staff accounts only — the admin can change the password from the dashboard.';

    private const CODE_TTL_MINUTES = 3;
    private const CODE_RESEND_WINDOW_SECONDS = 60;
    private const CODE_MAX_ATTEMPTS = 3;

    /**
     * How long after a notice is sent before the same admin address can trigger
     * another one. The notice exists to alert the Admin, not to be a way to
     * flood their inbox from many source addresses.
     */
    private const ADMIN_NOTICE_WINDOW_SECONDS = 1800;

    /**
     * Display the password reset request view.
     *
     * When a verification code is pending for an email (session), the view
     * shows the code-entry step instead of the email form. The reset form is
     * only ever reached after the emailed code is confirmed (see verify()).
     */
    public function create(): View
    {
        $pendingEmail = session('password_reset_email');
        $isFreshCodeSession = session()->has('codeSent');

        if (!$isFreshCodeSession && !empty($pendingEmail)) {
            session()->forget('password_reset_email');
            $pendingEmail = null;
        }

        return view('auth.forgot-password', [
            'status' => session('status'),
            'pendingEmail' => $pendingEmail,
        ]);
    }

    /**
     * Step 1: email a 6-digit verification code to the account. The reset form
     * itself is deliberately NOT opened here — it is only shown after the code
     * has been confirmed (see verify()).
     *
     * @throws ValidationException
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        // SECURITY: reset codes travel by email only. If production's default
        // mailer is `log`, the message (code included) would be written to
        // storage/logs and become account-takeover ammunition. That is a
        // deployment misconfiguration, so fail closed with the same vague
        // message instead of leaking the code.
        if (app()->environment('production') && (string)config('mail.default') === 'log') {
            throw ValidationException::withMessages([
                'email' => ['Please try again.'],
            ]);
        }

        $email = strtolower(trim($request->email));

        // Password recovery belongs to the staff portal: staff accounts live in
        // the `staff` table, and the admin address (which lives in `admins`) is
        // never eligible. Both admin and unknown addresses are rejected with the
        // same vague message so the admin account cannot be probed.
        $rejection = Staff::passwordResetRejection($email);
        if ($rejection !== null) {
            if ($rejection === 'admin') {
                $this->notifyAdminOfResetAttempt($request, $email);
            }

            throw ValidationException::withMessages([
                'email' => ['Please try again.'],
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

            return $this->wantsJson()
                ? response()->json(['status' => 'code_sent', 'email' => $email])
                : back()->with('codeSent', true);
        }

        $code = $this->createPasswordResetCode($email);

        Mail::to($email)->send(new PasswordResetCode(
            $code,
            $email,
            now()->addMinutes(self::CODE_TTL_MINUTES)->toDateTimeString()
        ));

        session(['password_reset_email' => $email]);

        return $this->wantsJson()
            ? response()->json(['status' => 'code_sent', 'email' => $email])
            : back()->with('codeSent', true);
    }

    /**
     * Step 2: confirm the emailed code, then take the user straight to the
     * reset-password form. No second email with a link is sent — the reset
     * token is handed to the already-verified browser directly. The code is
     * single-use and self-destructs after 3 failed attempts.
     *
     * @throws ValidationException
     */
    public function verify(Request $request): RedirectResponse|JsonResponse
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

        if (!$user || !Staff::canResetPassword($email)) {
            throw ValidationException::withMessages([
                'email' => ['Please try again.'],
            ]);
        }

        $token = Password::broker()->createToken($user);

        session()->forget('password_reset_email');

        return $this->wantsJson()
            ? response()->json(['status' => 'verified', 'token' => $token, 'email' => $email])
            : redirect()->route('password.reset', ['token' => $token, 'email' => $email]);
    }

    /**
     * Abandon a pending verification (wrong email, changed mind, etc.) and
     * return to the start of the forgot-password flow.
     */
    public function cancel(Request $request): RedirectResponse|JsonResponse
    {
        $this->ensurePasswordResetCodesTable();

        $email = strtolower(trim((string)session('password_reset_email', '')));
        if ($email !== '') {
            DB::table('password_reset_codes')
                ->whereRaw('LOWER(email) = ?', [$email])
                ->delete();
        }
        session()->forget('password_reset_email');

        return $this->wantsJson()
            ? response()->json(['status' => 'cancelled'])
            : redirect()->route('password.request');
    }

    /**
     * Alert the Admin that someone just tried the staff recovery flow with the
     * admin address: an admin password can never be reset here, so the attempt
     * is either a mistyped email or someone probing for the admin account, and
     * only the Admin can tell which.
     */
    private function notifyAdminOfResetAttempt(Request $request, string $adminEmail): void
    {
        // Wholly best-effort: the notice is a courtesy, so neither an
        // unavailable limiter/cache store nor a mail failure may change the
        // answer the form gives (the address is still not eligible).
        try {
            $throttleKey = 'admin-reset-notice:' . sha1(strtolower(trim($adminEmail)));

            if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
                return;
            }

            RateLimiter::hit($throttleKey, self::ADMIN_NOTICE_WINDOW_SECONDS);

            Mail::to($adminEmail)->send(new AdminResetAttempt(
                $adminEmail,
                now()->toDateTimeString(),
                (string) $request->ip(),
                trim((string) $request->userAgent()),
            ));
        } catch (Throwable $error) {
            report($error);
        }
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

    private function wantsJson(): bool
    {
        return request()->expectsJson() || request()->isXmlHttpRequest();
    }
}