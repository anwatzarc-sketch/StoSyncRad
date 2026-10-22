<?php

declare(strict_types=1);

namespace App\Support\Http;

use App\Support\Database\SqlError;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Validation\ValidationErrorCode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PDOException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;
use Throwable;

/**
 * Renders every exception into the uniform error envelope (Chapter 11 §11.4).
 * Never leaks SQL, stack traces or internal paths.
 */
final class ApiErrorResponder
{
    public function render(Throwable $e, Request $request): JsonResponse
    {
        $correlationId = Correlation::id($request);

        [$code, $message, $details, $hint, $errors] = $this->classify($e);

        $body = [
            'code' => $code->value,
            'message' => $message,
            'correlation_id' => $correlationId,
        ];
        if ($errors !== []) {
            $body['errors'] = $errors;
        }
        if ($details !== []) {
            $body['details'] = $details;
        }
        if ($hint !== null) {
            $body['recovery'] = ['hint' => $hint];
        }

        $response = new JsonResponse($body, $code->httpStatus());
        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Correlation-Id', $correlationId);
        if ($e instanceof TooManyRequestsHttpException || $code === ErrorCode::REPORT_CAPACITY) {
            foreach ($e instanceof HttpExceptionInterface ? $e->getHeaders() : [] as $h => $v) {
                $response->headers->set($h, (string) $v);
            }
        }

        return $response;
    }

    /**
     * @return array{0: ErrorCode, 1: string, 2: array<string,mixed>, 3: ?string, 4: array<int, array<string,mixed>>}
     */
    private function classify(Throwable $e): array
    {
        if ($e instanceof DomainException) {
            return [$e->errorCode, $e->getMessage(), $e->details, $e->recoveryHint, []];
        }

        if ($e instanceof ValidationException) {
            return [ErrorCode::VALIDATION_FAILED, 'The request contains invalid properties.', [],
                'Correct the listed properties and resubmit with the same Idempotency-Key.', $this->validationErrors($e)];
        }

        if ($e instanceof AuthenticationException) {
            return [ErrorCode::UNAUTHENTICATED, 'Authentication is required.', [], null, []];
        }

        if ($e instanceof AuthorizationException) {
            return [ErrorCode::PERMISSION_DENIED, 'You do not have the required permission for this operation.', [], null, []];
        }

        if ($e instanceof ModelNotFoundException || $e instanceof NotFoundHttpException) {
            return [ErrorCode::NOT_FOUND, 'The requested resource was not found.', [], null, []];
        }

        if ($e instanceof TooManyRequestsHttpException) {
            return [ErrorCode::RATE_LIMITED, 'Too many requests.', [], 'Wait and retry after the indicated period.', []];
        }

        // QueryException, and the DeadlockException (a PDOException) Laravel raises for nested deadlocks.
        if ($e instanceof QueryException || ($e instanceof PDOException && SqlError::errno($e) !== null)) {
            return $this->classifyQuery($e);
        }

        if ($e instanceof HttpExceptionInterface) {
            $code = match ($e->getStatusCode()) {
                400 => ErrorCode::MALFORMED_REQUEST,
                401 => ErrorCode::UNAUTHENTICATED,
                403 => ErrorCode::PERMISSION_DENIED,
                404 => ErrorCode::NOT_FOUND,
                413 => ErrorCode::PAYLOAD_TOO_LARGE,
                415 => ErrorCode::UNSUPPORTED_MEDIA_TYPE,
                429 => ErrorCode::RATE_LIMITED,
                503 => ErrorCode::MAINTENANCE,
                default => ErrorCode::INTERNAL_ERROR,
            };

            return [$code, $e->getMessage() !== '' ? $e->getMessage() : 'Request failed.', [], null, []];
        }

        return [ErrorCode::INTERNAL_ERROR, 'An unexpected error occurred. Quote the correlation id when reporting it.', [], null, []];
    }

    /** @return array{0: ErrorCode, 1: string, 2: array<string,mixed>, 3: ?string, 4: array<int, array<string,mixed>>} */
    private function classifyQuery(Throwable $e): array
    {
        $constraint = ['constraint' => SqlError::constraintName($e)];

        return match (SqlError::errno($e)) {
            SqlError::LOCK_WAIT_TIMEOUT, SqlError::LOCK_NOWAIT => [ErrorCode::LOCK_TIMEOUT, 'The record is locked by another operation.', [], 'Retry shortly with the same Idempotency-Key.', []],
            SqlError::DEADLOCK => [ErrorCode::CONCURRENCY_RETRIES_EXHAUSTED, 'The operation conflicted with a concurrent one.', [], 'Retry with the same Idempotency-Key.', []],
            SqlError::DUPLICATE_KEY => [ErrorCode::DUPLICATE_RESOURCE, 'A record with the same unique identity already exists.', $constraint, null, []],
            SqlError::CHECK_VIOLATION => [ErrorCode::CONSTRAINT_VIOLATION, 'The request violates a data integrity rule.', $constraint, null, []],
            SqlError::REFERENCED_ROW_MISSING => [ErrorCode::VALIDATION_FAILED, 'The request references a record that does not exist.', $constraint, null, []],
            SqlError::ROW_IS_REFERENCED => [ErrorCode::VALIDATION_FAILED, 'The record is still referenced by other records.', $constraint, null, []],
            SqlError::STATEMENT_TIMEOUT, SqlError::QUERY_INTERRUPTED => [ErrorCode::OPERATION_TIMEOUT, 'The operation exceeded its time limit and was cancelled.', [], 'Narrow the request or retry with the same Idempotency-Key.', []],
            SqlError::SIGNAL => SqlError::isAppendOnlyViolation($e)
                ? [ErrorCode::CONSTRAINT_VIOLATION, 'Append-only records cannot be modified.', [], null, []]
                : [ErrorCode::INTERNAL_ERROR, 'A database rule rejected the operation.', [], null, []],
            default => [ErrorCode::INTERNAL_ERROR, 'A database error occurred. Quote the correlation id when reporting it.', [], null, []],
        };
    }

    /** @return array<int, array<string, mixed>> */
    private function validationErrors(ValidationException $e): array
    {
        $out = [];
        foreach ($e->errors() as $property => $messages) {
            foreach ((array) $messages as $message) {
                [$text, $payload] = ValidationErrorCode::parse((string) $message);
                $out[] = ['property' => $property, 'message' => $text] + $payload;
            }
        }

        return $out;
    }
}
