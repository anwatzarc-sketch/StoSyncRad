<?php

declare(strict_types=1);

namespace App\Domains\Billing\Services;

use App\Domains\Catalog\Enums\AttachmentKind;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\AttachableType;
use App\Domains\Platform\Models\DocumentAttachment;
use App\Support\Enums\DataClassification;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Private evidence files (CP-1.1 section 7): the type is detected from the content (not the name or
 * the browser's claim), size and count are limited, files get random names on the private disk and are
 * only ever streamed back after the caller re-checked authorization. Nothing is publicly reachable.
 */
final class EvidenceStore
{
    /** detected MIME type => stored extension */
    public const ALLOWED = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'application/pdf' => 'pdf'];

    public function __construct(private readonly PlatformConfig $config) {}

    /** @param  array<int, mixed>  $files */
    public function validate(array $files, bool $required = true): void
    {
        $max = $this->config->int('portal.evidence.max_files');
        if ($required && $files === []) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Attach the bank slip, transfer screenshot or payment confirmation.', ['field' => 'evidence']);
        }
        if (count($files) > $max) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, "Attach at most $max files.", ['field' => 'evidence']);
        }
        $maxBytes = $this->config->int('portal.evidence.max_file_mb') * 1024 * 1024;
        foreach (array_values($files) as $i => $file) {
            $field = ['field' => "evidence.$i"];
            if (! $file instanceof UploadedFile || ! $file->isValid()) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'A file could not be uploaded. Try again.', $field);
            }
            if ($file->getSize() <= 0 || $file->getSize() > $maxBytes) {
                throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Each file must be smaller than '.$this->config->int('portal.evidence.max_file_mb').' MB.', $field);
            }
            $this->detect($file, $i);
        }
    }

    public function store(AttachableType $type, string $documentId, UploadedFile $file, AttachmentKind $kind, string $uploadedBy): DocumentAttachment
    {
        $mime = $this->detect($file, 0);
        $path = 'attachments/'.$type->value.'/'.$documentId.'/'.Str::uuid7().'.'.self::ALLOWED[$mime];
        Storage::disk('private')->put($path, (string) file_get_contents($file->getRealPath()));
        $name = mb_substr(preg_replace('/[^\pL\pN ._()-]+/u', '_', basename($file->getClientOriginalName())) ?: 'evidence', 0, 200);

        return DocumentAttachment::query()->create([
            'document_type' => $type, 'document_id' => $documentId, 'kind' => $kind, 'original_filename' => $name, 'private_path' => $path,
            'mime_type' => $mime, 'file_size' => $file->getSize(), 'checksum' => hash_file('sha256', $file->getRealPath()),
            'classification' => DataClassification::RESTRICTED, 'uploaded_by' => $uploadedBy,
        ]);
    }

    /** Stream a stored file as a download (never rendered inline, never executed). */
    public function download(DocumentAttachment $attachment): Response
    {
        abort_unless(Storage::disk('private')->exists($attachment->private_path), 404);

        return response(Storage::disk('private')->get($attachment->private_path), 200, [
            'Content-Type' => $attachment->mime_type,
            'Content-Disposition' => 'attachment; filename="'.addcslashes($attachment->original_filename, '"\\').'"',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /** Remove files written by a submission whose transaction did not commit. @param array<int, string> $paths */
    public function discard(array $paths): void
    {
        foreach ($paths as $path) {
            Storage::disk('private')->delete($path);
        }
    }

    private function detect(UploadedFile $file, int $i): string
    {
        $mime = (string) (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $ok = isset(self::ALLOWED[$mime]) && match ($mime) {
            'application/pdf' => str_starts_with((string) file_get_contents($file->getRealPath(), false, null, 0, 5), '%PDF-'),
            default => @getimagesize($file->getRealPath()) !== false,
        };
        if (! $ok) {
            throw DomainException::of(ErrorCode::UNSUPPORTED_MEDIA_TYPE, 'Upload a photo (JPG, PNG or WebP) or a PDF.', ['field' => "evidence.$i"]);
        }

        return $mime;
    }
}
