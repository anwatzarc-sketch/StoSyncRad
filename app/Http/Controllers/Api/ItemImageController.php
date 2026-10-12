<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Catalog\Services\ItemImageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/** Product photos of an item (staff): list, upload, choose the primary photo, delete, view. */
final class ItemImageController extends ApiController
{
    public function __construct(private readonly ItemImageService $images) {}

    public function index(string $id): JsonResponse
    {
        return response()->json(['data' => $this->images->list($id)]);
    }

    public function store(Request $request, string $id): JsonResponse
    {
        $image = $this->images->upload($id, $request->file('image'), $this->actor($request), $request->boolean('is_primary'));

        return response()->json(['data' => ['id' => $image->id, 'is_primary' => $image->is_primary]], 201);
    }

    public function primary(Request $request, string $id, string $imageId): JsonResponse
    {
        $this->images->makePrimary($id, $imageId, $this->actor($request));

        return response()->json(['data' => $this->images->list($id)]);
    }

    public function destroy(Request $request, string $id, string $imageId): JsonResponse
    {
        $this->images->delete($id, $imageId, $this->actor($request));

        return response()->json(['data' => $this->images->list($id)]);
    }

    public function show(string $id, string $imageId): Response
    {
        return $this->images->stream($id, $imageId);
    }
}
