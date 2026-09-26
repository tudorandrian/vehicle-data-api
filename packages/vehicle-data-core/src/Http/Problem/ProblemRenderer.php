<?php

declare(strict_types=1);

namespace VehicleData\Core\Http\Problem;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

final class ProblemRenderer
{
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->is('v1/*') && ! $request->is('openapi.yaml') && ! $request->is('docs') && ! $request->is('docs/*')) {
            return null;
        }

        $request->attributes->set('exception_class', $e::class);

        return match (true) {
            $e instanceof ValidationException => Problem::response(422, 'Unprocessable Content', 'One or more query parameters are invalid.', [
                'errors' => collect($e->errors())->flatMap(fn (array $messages, string $field) => array_map(
                    fn (string $m) => ['field' => $field, 'code' => 'invalid', 'message' => $m], $messages))->values()->all(),
            ], '/problems/validation'),
            $e instanceof AuthenticationException => Problem::response(401, 'Unauthorized', 'A valid bearer API key is required.', [], '/problems/unauthenticated'),
            $e instanceof AuthorizationException => Problem::response(403, 'Forbidden', 'You are not permitted to perform this action.', [], '/problems/forbidden'),
            // Defensive only: in practice this branch is currently unreachable.
            // Laravel's exception handler (prepareException) always converts a
            // ModelNotFoundException into a NotFoundHttpException (with the
            // original exception as ->getPrevious()) before any render callback
            // — including this one — ever runs. The real 404 case is handled by
            // the NotFoundHttpException branch below, which is why that branch
            // (not this one) is what keeps the model class name from leaking.
            $e instanceof ModelNotFoundException => Problem::response(404, 'Not Found', 'No such resource.', [], '/problems/not-found'),
            $e instanceof NotFoundHttpException => Problem::response(404, 'Not Found', 'No such resource.', [], '/problems/not-found'),
            $e instanceof AccessDeniedHttpException => Problem::response(403, 'Forbidden', 'You are not permitted to perform this action.', [], '/problems/forbidden'),
            $e instanceof ThrottleRequestsException => Problem::response(429, 'Too Many Requests', 'Rate limit exceeded.', [], '/problems/rate-limited')
                ->withHeaders($e->getHeaders()),
            $e instanceof HttpExceptionInterface => Problem::response($e->getStatusCode(), self::title($e->getStatusCode()), $e->getMessage() !== '' ? $e->getMessage() : null)
                ->withHeaders($e->getHeaders()),
            default => Problem::response(500, 'Internal Server Error', 'The request could not be completed.', [], '/problems/internal'),
        };
    }

    private static function title(int $status): string
    {
        return match ($status) {
            400 => 'Bad Request', 404 => 'Not Found', 405 => 'Method Not Allowed', 406 => 'Not Acceptable',
            413 => 'Payload Too Large', 429 => 'Too Many Requests', 503 => 'Service Unavailable', default => 'Error',
        };
    }
}
