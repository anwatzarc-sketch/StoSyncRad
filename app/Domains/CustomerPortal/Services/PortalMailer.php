<?php

declare(strict_types=1);

namespace App\Domains\CustomerPortal\Services;

use App\Domains\Messaging\Services\TransactionalMailer;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Templates\DocumentPrintService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Customer Portal e-mails rendered from the editable templates (Administration → Templates). Delivery
 * problems are logged, never shown to the visitor, and never undo the business action that sent them.
 */
final class PortalMailer
{
    public function __construct(private readonly DocumentPrintService $documents, private readonly BrandResolver $brand, private readonly TransactionalMailer $mailer) {}

    /** @param  array<string, mixed>  $vars */
    public function send(string $templateCode, string $to, string $subject, array $vars): void
    {
        $body = (string) DB::table('templates')->where('code', $templateCode)->where('is_active', true)
            ->orderByDesc('is_system_default')->orderByDesc('updated_at')->value('body');
        if ($body === '') {
            Log::warning('Customer portal e-mail template missing', ['template' => $templateCode]);

            return;
        }
        $html = $this->documents->renderer()->render($body, $vars + ['brand' => $this->brand->publicProfile()]);
        try {
            $this->mailer->html($to, $subject, $html);
        } catch (\Throwable $e) {
            Log::warning('Customer portal e-mail not delivered', ['template' => $templateCode, 'error' => $e->getMessage()]);
        }
    }
}
