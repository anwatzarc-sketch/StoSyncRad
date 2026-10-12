<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\IdentityAccess\Models\UserAccount;
use App\Http\Support\Presenter;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Schema\ValidateAgainstSchema;
use App\Support\Transactions\PostingOutcome;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/** Shared helpers for API controllers (Chapter 11). */
abstract class ApiController extends Controller
{
    protected function actor(Request $request): UserAccount
    {
        $user = $request->user();
        if (! $user instanceof UserAccount) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Sign in to continue.');
        }

        return $user;
    }

    /** Payload already validated by the global schema middleware (or the raw JSON body when the route declares no schema). */
    protected function payload(Request $request): array
    {
        return (array) ($request->attributes->get(ValidateAgainstSchema::ATTRIBUTE) ?? $request->json()->all() ?? []);
    }

    protected function idempotencyKey(Request $request): ?string
    {
        return $request->header('Idempotency-Key');
    }

    protected function presenter(): Presenter
    {
        return app(Presenter::class);
    }

    protected function respond(mixed $data, int $status = 200, array $headers = []): JsonResponse
    {
        if ($data instanceof PostingOutcome) {
            return response()->json(['data' => $this->presenter()->data($data->body)], $data->replayed ? 200 : $data->status,
                $headers + array_filter(['Idempotent-Replayed' => $data->replayed ? 'true' : null, 'X-Retries' => $data->retries ? (string) $data->retries : null]));
        }
        if ($data instanceof Model) {
            return response()->json(['data' => $this->presenter()->model($data)], $status, $headers);
        }

        return response()->json(['data' => $this->presenter()->data($data)], $status, $headers);
    }
}
