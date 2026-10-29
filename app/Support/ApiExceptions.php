<?php
namespace App\Support;

use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

class ApiExceptions
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (ValidationException $e, $request) {
            if ($request->is('api/*')) {
                return Api::error('VALIDATION_ERROR', 'The request contains invalid fields.', 422, $e->errors());
            }
        });
        $exceptions->render(function (AuthenticationException $e, $request) {
            if ($request->is('api/*')) {
                return Api::error('UNAUTHENTICATED', 'Authentication required.', 401);
            }
        });
        $exceptions->render(function (NotFoundHttpException $e, $request) {
            if ($request->is('api/*')) {
                return Api::error('NOT_FOUND', 'The requested resource was not found.', 404);
            }
        });
        $exceptions->render(function (TooManyRequestsHttpException $e, $request) {
            if ($request->is('api/*')) {
                return Api::error('TOO_MANY_REQUESTS', 'Too many requests. Please slow down.', 429);
            }
        });
    }
}
