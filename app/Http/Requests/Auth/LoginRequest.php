<?php

namespace App\Http\Requests\Auth;

use Illuminate\Auth\Events\Lockout;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\ValidationException;
use App\Services\SecurityLogger;
use App\Support\SecurityResponsePolicy;

class LoginRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\Rule|array|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'captcha_token' => ['nullable', 'string'],
        ];
    }

    /**
     * Attempt to authenticate the request's credentials.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function authenticate(): void
    {
        $this->ensureIsNotRateLimited();

        if (! Auth::attempt($this->only('email', 'password'), $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey());

            SecurityLogger::log('auth_login_failed', [
                'request_id' => SecurityLogger::requestId(),
                'ip' => $this->ip(),
                'user_agent_hash' => SecurityLogger::hashValue($this->userAgent()),
                'user_id' => null,
                'email_hash' => SecurityLogger::hashValue((string) $this->string('email')),
                'method' => $this->method(),
                'path' => '/' . ltrim($this->path(), '/'),
                'status' => 302,
                'latency_ms' => SecurityLogger::latencyMs(),
                'query_hash' => null,
            ]);

            throw ValidationException::withMessages([
                'email' => trans('auth.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
    }

    /**
     * Ensure the login request is not rate limited.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (SecurityResponsePolicy::isIpFlagged('throttle_ips', $this->ip())) {
            if ($this->expectsJson()) {
                throw new HttpResponseException(response()->json([
                    'error' => 'THROTTLED',
                    'message' => 'Temporary login throttle is active for your IP. Please retry later.',
                ], 429));
            }
            throw ValidationException::withMessages([
                'email' => 'Temporary login throttle is active for your IP. Please retry later.',
            ]);
        }

        if (SecurityResponsePolicy::isIpFlagged('captcha_ips', $this->ip())) {
            $token = (string) $this->string('captcha_token');
            $expected = (string) config('security.demo_captcha_token', 'demo-ok');
            if ($token !== $expected) {
                if ($this->expectsJson()) {
                    throw new HttpResponseException(response()->json([
                        'error' => 'CHALLENGE_REQUIRED',
                        'message' => 'Additional verification required. Provide captcha_token to continue.',
                    ], 403));
                }
                throw ValidationException::withMessages([
                    'email' => 'Additional verification required. Provide captcha_token to continue.',
                ]);
            }
        }

        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        if ($this->expectsJson()) {
            throw new HttpResponseException(response()->json([
                'error' => 'RATE_LIMITED',
                'message' => trans('auth.throttle', [
                    'seconds' => $seconds,
                    'minutes' => ceil($seconds / 60),
                ]),
            ], 429));
        }

        throw ValidationException::withMessages([
            'email' => trans('auth.throttle', [
                'seconds' => $seconds,
                'minutes' => ceil($seconds / 60),
            ]),
        ]);
    }

    /**
     * Get the rate limiting throttle key for the request.
     */
    public function throttleKey(): string
    {
        return Str::transliterate(Str::lower($this->string('email')).'|'.$this->ip());
    }
}
