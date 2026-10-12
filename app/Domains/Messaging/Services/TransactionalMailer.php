<?php

declare(strict_types=1);

namespace App\Domains\Messaging\Services;

use App\Domains\Messaging\Enums\MailSecurity;
use App\Domains\Messaging\Enums\MailTransport;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\BrandResolver;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;

/**
 * The single way the application sends e-mail (password resets, notifications, customer portal).
 * Delivery follows `messaging.mail.transport`: the server's .env mailer, the SMTP account configured in
 * Administration → Configuration (password stored encrypted), or the log file. Settings are read at
 * send time, so a change applies without a restart.
 */
final class TransactionalMailer
{
    private const MAILER = 'configured_smtp';

    public function __construct(private readonly PlatformConfig $config, private readonly BrandResolver $brand) {}

    public function html(string $to, string $subject, string $html): void
    {
        Mail::mailer($this->mailer())->html($html, fn (Message $m) => $this->address($m, $to, $subject));
    }

    public function text(string $to, string $subject, string $text): void
    {
        Mail::mailer($this->mailer())->raw($text, fn (Message $m) => $this->address($m, $to, $subject));
    }

    /** Send a test message; returns the delivery route used (errors propagate to the caller). @return array<string, string> */
    public function test(string $to): array
    {
        $mailer = $this->mailer();
        $product = $this->brand->publicProfile()['product']['name'] ?? 'StockSync';
        $this->html($to, "$product test e-mail", "<p>This is a test message from $product.</p><p>If you can read it, e-mail delivery works.</p>");

        return ['mailer' => $mailer, 'transport' => $this->transport()->value, 'from' => $this->fromAddress()];
    }

    public function transport(): MailTransport
    {
        try {
            return MailTransport::tryFrom((string) $this->config->get('messaging.mail.transport')) ?? MailTransport::ENVIRONMENT;
        } catch (\Throwable) {
            return MailTransport::ENVIRONMENT;
        }
    }

    /** Name of the Laravel mailer to use, (re)built from the current settings. */
    private function mailer(): string
    {
        return match ($this->transport()) {
            MailTransport::LOG => 'log',
            MailTransport::ENVIRONMENT => (string) config('mail.default'),
            MailTransport::SMTP => $this->smtp(),
        };
    }

    private function smtp(): string
    {
        $security = MailSecurity::tryFrom((string) $this->config->get('messaging.mail.smtp.security')) ?? MailSecurity::STARTTLS;
        config(['mail.mailers.'.self::MAILER => [
            'transport' => 'smtp',
            'scheme' => $security === MailSecurity::SSL ? 'smtps' : 'smtp',
            'host' => (string) $this->config->get('messaging.mail.smtp.host'),
            'port' => $this->config->int('messaging.mail.smtp.port'),
            'username' => (string) $this->config->get('messaging.mail.smtp.username') ?: null,
            'password' => (string) $this->config->get('messaging.mail.smtp.password') ?: null,
            'timeout' => 20,
        ]]);
        Mail::purge(self::MAILER);   // rebuild with the current settings

        return self::MAILER;
    }

    private function address(Message $m, string $to, string $subject): void
    {
        $m->to($to)->subject($subject);
        $from = $this->fromAddress();
        if ($from !== '') {
            $m->from($from, $this->fromName());
        }
    }

    private function fromAddress(): string
    {
        $configured = (string) $this->config->get('messaging.mail.from_address');
        if ($configured !== '') {
            return $configured;
        }

        // An SMTP account normally only accepts its own address as the sender.
        return $this->transport() === MailTransport::SMTP ? (string) $this->config->get('messaging.mail.smtp.username') : '';
    }

    private function fromName(): ?string
    {
        $brand = $this->brand->publicProfile();

        return ((string) $this->config->get('messaging.mail.from_name')) ?: ($brand['company_name'] ?? $brand['app_name'] ?? null);
    }
}
