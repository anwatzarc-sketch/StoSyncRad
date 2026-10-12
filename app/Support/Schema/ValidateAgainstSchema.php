<?php

declare(strict_types=1);

namespace App\Support\Schema;

use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global validation middleware (Chapter 05 §5.4).
 *
 * Runs on every unsafe request whose route declares a schema:
 *   Route::post('/items', ...)->schema(ItemSchema::class, 'create');
 *   Route::patch('/items/{item}', ...)->schema(ItemSchema::class, 'update', stateFrom: 'item');
 *
 * The coerced payload is stored on the request as `schema_validated` and exposed through
 * SchemaValidated::from($request) for FormRequests/Actions.
 */
final class ValidateAgainstSchema
{
    public const ATTRIBUTE = 'schema_validated';

    public function __construct(private readonly SchemaValidator $validator) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $declaration = $request->route()?->getAction('schema');
        if ($declaration === null) {
            if ($request->route()?->getAction('no_body') === true && $this->payload($request) !== [] && ! app(PlatformConfig::class)->bool('api.allow_unknown_properties')) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'This action takes no request body.',
                    ['errors' => array_map(fn ($k) => ['field' => $k, 'code' => 'UNKNOWN_PROPERTY'], array_keys($this->payload($request)))]);
            }

            return $next($request);
        }

        [$schemaClass, $operation, $stateFrom] = $declaration + [null, 'create', null];

        $config = app(PlatformConfig::class);
        $maxKb = (int) $config->get('api.max_body_kb');
        if (! $this->isMultipart($request) && strlen($request->getContent()) > $maxKb * 1024) {
            throw DomainException::of(ErrorCode::PAYLOAD_TOO_LARGE, "Request body exceeds {$maxKb} KB.");
        }

        $payload = $this->payload($request);

        $actor = $request->user();
        $can = $actor !== null
            ? fn (string $permission): bool => app(Access::class)->can($actor, $permission)
            : fn (string $permission): bool => false;

        $currentState = null;
        if ($operation === 'update' && $stateFrom !== null) {
            $target = $request->route($stateFrom);
            $stateColumn = $schemaClass::stateColumn();
            $state = is_object($target) ? ($target->{$stateColumn} ?? null) : null;
            $currentState = $state instanceof \BackedEnum ? (string) $state->value : ($state !== null ? (string) $state : null);
        }

        $context = new ValidationContext(
            operation: $operation,
            can: $can,
            currentState: $currentState,
            allowUnknown: (bool) $config->get('api.allow_unknown_properties'),
        );

        $validated = $this->validator->validate($schemaClass, $payload, $context);
        $request->attributes->set(self::ATTRIBUTE, $validated);

        return $next($request);
    }

    /** JSON body, or the form fields of a form/multipart body (uploaded files are checked by their handler). */
    private function payload(Request $request): array
    {
        if ($request->isJson()) {
            return (array) $request->json()->all();
        }

        return array_diff_key($request->request->all(), ['_token' => 1, '_method' => 1]);
    }

    private function isMultipart(Request $request): bool
    {
        return str_starts_with(strtolower((string) $request->headers->get('Content-Type')), 'multipart/form-data');
    }
}
