<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Billing\Models\PaymentConfirmation;
use App\Domains\Billing\Services\EvidenceStore;
use App\Domains\Platform\Enums\AttachableType;
use App\Domains\Platform\Models\DocumentAttachment;
use App\Support\Exceptions\DomainException;
use Symfony\Component\HttpFoundation\Response;

/** Staff download of customer payment evidence (permission checked by the route; file streamed privately). */
final class BillingFilesController extends ApiController
{
    public function evidence(string $id, string $attachmentId, EvidenceStore $store): Response
    {
        $confirmation = PaymentConfirmation::query()->findOrFail($id);
        $attachment = DocumentAttachment::query()->whereKey($attachmentId)->where('document_type', AttachableType::PAYMENT_CONFIRMATION->value)
            ->where('document_id', $confirmation->id)->first() ?? throw DomainException::notFound('file');

        return $store->download($attachment);
    }
}
