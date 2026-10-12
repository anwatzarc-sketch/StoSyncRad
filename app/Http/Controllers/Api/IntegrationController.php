<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Domains\Fulfillment\Enums\ShipmentStatus;
use App\Domains\Fulfillment\Models\Shipment;
use App\Domains\Fulfillment\Services\ShipmentWorkflow;
use App\Domains\Messaging\Services\WebhookService;
use App\Domains\Platform\Config\PlatformConfig;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inbound integrations (Chapter 11 §11.8). Carrier status webhooks are verified with the shared
 * HMAC secret and only change shipment status through the fulfillment action (delivery confirmation
 * may post the sale when configured). Replays are idempotent per carrier event id.
 */
final class IntegrationController extends ApiController
{
    public function __construct(private readonly PlatformConfig $config) {}

    public function carrierEvent(Request $request, ShipmentWorkflow $shipments, string $carrier): JsonResponse
    {
        if (! $this->config->bool('integration.carrier.enabled')) {
            throw DomainException::featureDisabled('integration.carrier.enabled');
        }
        if (! WebhookService::verify($request->header('X-Webhook-Signature'), $request->getContent(), (string) $this->config->get('integration.carrier.webhook_secret'))) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Invalid signature.');
        }
        $payload = (array) $request->json()->all();
        $status = ShipmentStatus::tryFrom((string) ($payload['status'] ?? '')) ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED,
            'Unknown status. Allowed: '.implode(', ', ShipmentStatus::values()).'.', ['field' => 'status']);
        $shipment = Shipment::query()->where('tracking_number', (string) ($payload['tracking_number'] ?? ''))->first()
            ?? Shipment::query()->where('document_number', (string) ($payload['shipment_number'] ?? ''))->firstOrFail();
        $eventId = (string) ($payload['event_id'] ?? hash('sha256', $request->getContent()));
        $h = md5("carrier|$carrier|$eventId");
        $key = sprintf('%s-%s-7%s-8%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 13, 3), substr($h, 17, 3), substr($h, 20, 12));
        $outcome = $shipments->recordEvent($shipment->id, null, $key, ['status' => $status->value, 'occurred_at' => $payload['occurred_at'] ?? null,
            'payload' => array_intersect_key($payload, array_flip(['event_id', 'status', 'location', 'description', 'occurred_at']))], 'carrier:'.mb_substr($carrier, 0, 40));

        return $this->respond($outcome);
    }
}
