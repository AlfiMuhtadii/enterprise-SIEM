<?php

namespace App\Exceptions;

use App\Exceptions\TenantSpoofAttemptException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Throwable;

class Handler extends ExceptionHandler
{
    protected $dontReport = [
        TenantBoundaryViolationException::class,
        TenantSpoofAttemptException::class,
    ];

    /**
     * The list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            //
        });

        $this->renderable(function (TenantBoundaryViolationException $e) {
            if (request()->expectsJson()) {
                return response()->json(['error' => 'Forbidden', 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        });

        $this->renderable(function (TenantSpoofAttemptException $e) {
            if (request()->expectsJson()) {
                return response()->json(['error' => 'Forbidden', 'message' => $e->getMessage()], 403);
            }
            abort(403, $e->getMessage());
        });
    }
}
