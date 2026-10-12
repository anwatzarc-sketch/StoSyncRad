<?php

declare(strict_types=1);

namespace App\Domains\Platform\Models;

use App\Domains\Catalog\Enums\AttachmentKind;
use App\Domains\Platform\Enums\AttachableType;
use App\Support\Eloquent\BaseModel;
use App\Support\Enums\DataClassification;

/**
 * Private attachment on any document or master record (Chapter 14 §14.4). Served only through
 * an authorized streamed route; the storage path is never exposed.
 *
 * @property AttachableType $document_type
 * @property string $document_id
 * @property AttachmentKind $kind
 * @property string $original_filename
 * @property string $private_path
 * @property string $mime_type
 * @property int $file_size
 * @property string $checksum
 * @property DataClassification $classification
 * @property string $uploaded_by
 */
final class DocumentAttachment extends BaseModel
{
    protected $table = 'document_attachments';

    protected $hidden = ['private_path'];

    protected $fillable = ['document_type', 'document_id', 'kind', 'original_filename', 'private_path', 'mime_type', 'file_size', 'checksum', 'classification', 'uploaded_by'];

    protected function casts(): array
    {
        return ['document_type' => AttachableType::class, 'kind' => AttachmentKind::class, 'classification' => DataClassification::class, 'file_size' => 'integer'];
    }
}
