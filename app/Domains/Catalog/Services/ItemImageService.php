<?php

declare(strict_types=1);

namespace App\Domains\Catalog\Services;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\Catalog\Models\Item;
use App\Domains\Catalog\Models\ItemImage;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Product photos shown in the catalogue and the customer portal. The type is detected from the content
 * (JPEG, PNG or WebP only — never SVG or anything executable), size and count are limited, files get
 * random names on the private disk and are streamed back only through authorized endpoints. The first
 * image of an item is its primary image; exactly one image is primary while any exist.
 */
final class ItemImageService
{
    /** detected MIME type => stored extension */
    public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];

    public function __construct(private readonly PlatformConfig $config, private readonly AuditRecorder $audit) {}

    /** @return array<int, array<string, mixed>> */
    public function list(string $itemId): array
    {
        Item::query()->findOrFail($itemId);

        return ItemImage::query()->where('item_id', $itemId)->orderByDesc('is_primary')->orderBy('sort_order')->get()
            ->map(fn (ItemImage $i) => ['id' => $i->id, 'mime' => $i->mime, 'is_primary' => $i->is_primary, 'sort_order' => $i->sort_order,
                'url' => "/api/v1/items/{$itemId}/images/{$i->id}", 'created_at' => $i->created_at?->toIso8601String()])->all();
    }

    public function upload(string $itemId, mixed $file, UserAccount $actor, bool $primary = false): ItemImage
    {
        if (! $file instanceof UploadedFile || ! $file->isValid()) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose a photo to upload.', ['field' => 'image']);
        }
        $maxMb = $this->config->int('catalog.images.max_file_mb');
        if ($file->getSize() <= 0 || $file->getSize() > $maxMb * 1024 * 1024) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "The photo must be smaller than $maxMb MB.", ['field' => 'image']);
        }
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $size = isset(self::ALLOWED[$mime]) ? @getimagesize($file->getRealPath()) : false;
        if ($size === false || $size[0] < 1 || $size[1] < 1 || $size[0] > 10000 || $size[1] > 10000) {
            throw DomainException::of(ErrorCode::UNSUPPORTED_MEDIA_TYPE, 'Upload a JPG, PNG or WebP photo (at most 10000 × 10000 pixels).', ['field' => 'image']);
        }
        $path = 'item-images/'.$itemId.'/'.Str::uuid7().'.'.self::ALLOWED[$mime];

        try {
            return DB::transaction(function () use ($itemId, $file, $actor, $primary, $mime, $path) {
                $item = Item::query()->lockForUpdate()->findOrFail($itemId);
                $count = ItemImage::query()->where('item_id', $item->id)->count();
                $max = $this->config->int('catalog.images.max_per_item');
                if ($count >= $max) {
                    throw DomainException::of(ErrorCode::POLICY_VIOLATION, "An item can have at most $max photos. Delete one first.", ['field' => 'image']);
                }
                Storage::disk('private')->put($path, (string) file_get_contents($file->getRealPath()));
                $primary = $primary || $count === 0;
                if ($primary) {
                    ItemImage::query()->where('item_id', $item->id)->update(['is_primary' => false]);
                }
                $image = ItemImage::query()->create(['item_id' => $item->id, 'path' => $path, 'mime' => $mime, 'is_primary' => $primary,
                    'sort_order' => (int) ItemImage::query()->where('item_id', $item->id)->max('sort_order') + 1]);
                $this->audit->record($actor, 'catalog.item.image_added', 'items', $item->id, null,
                    ['image_id' => $image->id, 'mime' => $mime, 'bytes' => $file->getSize(), 'primary' => $primary]);

                return $image;
            });
        } catch (\Throwable $e) {
            Storage::disk('private')->delete($path);
            throw $e;
        }
    }

    public function makePrimary(string $itemId, string $imageId, UserAccount $actor): ItemImage
    {
        return DB::transaction(function () use ($itemId, $imageId, $actor) {
            Item::query()->lockForUpdate()->findOrFail($itemId);
            $image = $this->find($itemId, $imageId);
            ItemImage::query()->where('item_id', $itemId)->where('id', '<>', $image->id)->update(['is_primary' => false]);
            $image->forceFill(['is_primary' => true])->save();
            $this->audit->record($actor, 'catalog.item.image_primary', 'items', $itemId, null, ['image_id' => $image->id]);

            return $image;
        });
    }

    public function delete(string $itemId, string $imageId, UserAccount $actor): void
    {
        $path = DB::transaction(function () use ($itemId, $imageId, $actor) {
            Item::query()->lockForUpdate()->findOrFail($itemId);
            $image = $this->find($itemId, $imageId);
            $image->delete();
            if ($image->is_primary) {
                ItemImage::query()->where('item_id', $itemId)->orderBy('sort_order')->first()?->forceFill(['is_primary' => true])->save();
            }
            $this->audit->record($actor, 'catalog.item.image_deleted', 'items', $itemId, ['image_id' => $image->id, 'primary' => $image->is_primary], null);

            return $image->path;
        });
        Storage::disk('private')->delete($path);
    }

    /** Stream an image inline (content type fixed at upload; never sniffed, never scripted). */
    public function stream(string $itemId, string $imageId): Response
    {
        $image = $this->find($itemId, $imageId);
        abort_unless(Storage::disk('private')->exists($image->path), 404);

        return response(Storage::disk('private')->get($image->path), 200, ['Content-Type' => $image->mime, 'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox", 'Cache-Control' => 'private, max-age=3600']);
    }

    private function find(string $itemId, string $imageId): ItemImage
    {
        return ItemImage::query()->where('item_id', $itemId)->whereKey($imageId)->first() ?? throw DomainException::notFound('image');
    }
}
