<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Transactions\PostingOutcome;
use BackedEnum;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Invokes a workflow/service method for a document action (submit, approve, post, dispatch…):
 *
 *   Route::post('/stock-requests/{id}/approve', WorkflowActionController::class)
 *       ->defaults('_action', [StockRequestWorkflow::class, 'approve'])->permission(...)->schema(LineApprovalSchema::class);
 *
 * Arguments are bound by name: route parameters (`id`, `salesOrderId`…), `actor`, `idempotencyKey`
 * (Idempotency-Key header), `data`/`options` (the whole validated payload) and every other parameter
 * from the payload's snake_case key (`overrideReason` ← override_reason, `approvedLines`/`results`/
 * `picks` ← lines). Enum-typed parameters are converted with ::from(). Authorization, scope, state and
 * business rules stay inside the service — the controller only adapts HTTP.
 */
final class WorkflowActionController extends ApiController
{
    private const PAYLOAD_ALIASES = ['approvedLines' => 'lines', 'results' => 'lines', 'picks' => 'lines', 'lineIds' => 'line_ids', 'note' => 'reason'];

    public function __invoke(Request $request): JsonResponse
    {
        [$class, $method] = $request->route()->getAction('_action');
        $payload = $request->isMethod('GET') ? $request->query() : $this->payload($request);
        $actor = $this->actor($request);
        $args = [];
        foreach ((new ReflectionMethod($class, $method))->getParameters() as $param) {
            $name = $param->getName();
            $type = $param->getType();
            $typeName = $type instanceof ReflectionNamedType ? $type->getName() : null;
            if ($request->route()->hasParameter($name)) {
                $args[] = (string) $request->route($name);

                continue;
            }
            if ($typeName === UserAccount::class) {
                $args[] = $actor;

                continue;
            }
            if ($name === 'idempotencyKey') {
                $args[] = $this->idempotencyKey($request);

                continue;
            }
            if (in_array($name, ['data', 'options', 'scope'], true)) {
                $args[] = $payload;

                continue;
            }
            $key = self::PAYLOAD_ALIASES[$name] ?? Str::snake($name);
            if (array_key_exists($key, $payload)) {
                $value = $payload[$key];
                if ($typeName !== null && is_subclass_of($typeName, BackedEnum::class)) {
                    $value = $value === null ? null : ($typeName::tryFrom((string) $value) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
                        "`$value` is not a valid ".str_replace('_', ' ', $key).'. Allowed: '.implode(', ', array_map(fn ($c) => $c->value, $typeName::cases())).'.', ['field' => $key]));
                } elseif ($typeName === 'int' && $value !== null) {
                    $value = (int) $value;
                } elseif ($typeName === 'bool') {
                    $value = (bool) $value;
                } elseif ($typeName === 'string' && $value !== null && ! is_string($value)) {
                    $value = (string) $value;
                }
                $args[] = $value;

                continue;
            }
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();

                continue;
            }
            if ($type?->allowsNull()) {
                $args[] = null;

                continue;
            }
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "`$key` is required.", ['field' => $key]);
        }

        $result = app($class)->{$method}(...$args);

        return match (true) {
            $result instanceof PostingOutcome => $this->respond($result),
            $result instanceof Model => $this->respond($result->refresh()),
            default => $this->respond($result),
        };
    }
}
