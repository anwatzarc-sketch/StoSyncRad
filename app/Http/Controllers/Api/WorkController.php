<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Support\WorkflowCatalog;
use App\Http\Support\WorkQueues;
use App\Support\Exceptions\DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * "My work" (Chapter 12 §12.6): what is waiting for the signed-in person, per workflow hub. Like
 * `/me` it is bound to no single permission — every queue is counted and listed only where the person
 * holds the permission of the action it waits for.
 *   GET /work?hub=receiving        hubs, start actions, queues with counts, waiting queues, alerts
 *   GET /work/queues/{queue}       the documents of one queue, oldest first (cursor-paginated)
 */
final class WorkController extends ApiController
{
    public function __construct(private readonly WorkQueues $work) {}

    public function index(Request $request): JsonResponse
    {
        $hub = $request->query('hub');
        if ($hub !== null && ! isset(WorkflowCatalog::HUBS[$hub])) {
            throw DomainException::notFound('Workflow');
        }

        return response()->json(['data' => $this->work->summary($this->actor($request), $hub)]);
    }

    public function queue(Request $request, string $queue): JsonResponse
    {
        $definition = WorkflowCatalog::queues()[$queue] ?? throw DomainException::notFound('Queue');
        $actor = $this->actor($request);
        if (! $this->work->queueVisible($actor, $definition)) {
            throw DomainException::permissionDenied($definition['permission']);
        }
        $perPage = max(1, min(100, (int) $request->query('per_page', 25)));
        $page = $this->work->query($actor, $definition)->cursorPaginate($perPage);

        return response()->json([
            'data' => $this->presenter()->collection($page->items()),
            'meta' => ['per_page' => $perPage, 'next_cursor' => $page->nextCursor()?->encode(), 'prev_cursor' => $page->previousCursor()?->encode(),
                'resource' => $definition['resource'], 'queue' => $queue],
        ]);
    }
}
