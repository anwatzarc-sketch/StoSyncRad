<?php

declare(strict_types=1);

namespace App\Domains\Platform\Setup;

use App\Domains\Audit\Enums\AuditActorType;
use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\Messaging\Services\Outbox;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Models\PlatformSetup;
use App\Domains\Platform\Services\Preflight;
use App\Domains\Platform\Services\SetupState;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform Setup state machine (Chapter 02 §2.2–2.7).
 *
 *  bootstrap()   creates the setup row and a single-use, expiring bootstrap token (hash stored)
 *  exchange()    consumes the token (single use) to open a setup session
 *  complete()    validates ordering, runs the step handler, records completion (audited)
 *  lock()        requires every mandatory step + clean preflight, locks setup and enables login
 *  reopen()      for holders of platform.setup.reopen while in maintenance mode
 */
final class SetupService
{
    public function __construct(
        private readonly SetupHandlers $handlers,
        private readonly Preflight $preflight,
        private readonly AuditRecorder $audit,
        private readonly Outbox $outbox,
        private readonly SetupState $state,
        private readonly Access $access,
    ) {}

    public function current(): PlatformSetup
    {
        $row = PlatformSetup::query()->first();
        if ($row === null) {
            $row = new PlatformSetup;
            $row->forceFill([
                'deployment_id' => (string) Str::uuid7(),
                'state' => PlatformSetupState::NOT_STARTED,
                'current_step' => PlatformSetupStep::WELCOME,
                'completed_steps' => [],
            ])->save();
            $this->state->refresh();
        }

        return $row;
    }

    /** Issue a fresh single-use bootstrap token. Returns the plaintext (shown once). */
    public function bootstrap(?int $ttlMinutes = null): string
    {
        return DB::transaction(function () use ($ttlMinutes) {
            $row = PlatformSetup::query()->lockForUpdate()->first() ?? $this->current();
            if (in_array($row->state, [PlatformSetupState::COMPLETED], true)) {
                throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Platform Setup is already completed and locked.');
            }
            $token = Str::random(48);
            $row->forceFill([
                'state' => $row->state === PlatformSetupState::NOT_STARTED ? PlatformSetupState::IN_PROGRESS : $row->state,
                'bootstrap_token_hash' => hash('sha256', $token),
                'bootstrap_token_expires_at' => now()->addMinutes($ttlMinutes ?? (int) config('platform.setup_token_ttl_minutes', 30)),
            ])->save();
            $this->audit->record(null, 'platform.setup.token_issued', 'platform_setup', $row->id, actorType: AuditActorType::SETUP);
            $this->state->refresh();

            return $token;
        });
    }

    /** Consume a bootstrap token (single use). */
    public function exchange(string $token): PlatformSetup
    {
        return DB::transaction(function () use ($token) {
            $row = PlatformSetup::query()->lockForUpdate()->first();
            $valid = $row !== null && $row->bootstrap_token_hash !== null && hash_equals($row->bootstrap_token_hash, hash('sha256', $token))
                && $row->bootstrap_token_expires_at !== null && $row->bootstrap_token_expires_at->isFuture()
                && in_array($row->state, [PlatformSetupState::IN_PROGRESS, PlatformSetupState::REOPENED], true);
            if (! $valid) {
                throw DomainException::of(ErrorCode::SETUP_TOKEN_INVALID, 'The setup token is invalid, expired or already used.', [],
                    'Run `php artisan platform:bootstrap` to issue a new token.');
            }
            $row->forceFill(['bootstrap_token_hash' => null, 'bootstrap_token_expires_at' => null])->save();
            $this->audit->record(null, 'platform.setup.token_exchanged', 'platform_setup', $row->id, actorType: AuditActorType::SETUP);

            return $row;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function complete(PlatformSetupStep $step, array $payload, SetupContext $context): array
    {
        $row = $this->current();
        if (! in_array($row->state, [PlatformSetupState::IN_PROGRESS, PlatformSetupState::REOPENED], true)) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Platform Setup is not open.');
        }
        foreach (PlatformSetupStep::cases() as $earlier) {
            if ($earlier->order() >= $step->order()) {
                break;
            }
            if (! $row->isStepCompleted($earlier) && ! $earlier->isOptional()) {
                throw DomainException::of(ErrorCode::SETUP_STEP_ORDER, "Complete the `{$earlier->value}` step first.", ['required_step' => $earlier->value]);
            }
        }
        if ($step->requiresSuperAdmin() && ($context->actor === null || ! $context->actor->is_super_admin)) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Sign in as the Super Admin to continue setup.');
        }
        if ($step === PlatformSetupStep::REVIEW_LOCK) {
            $this->lock($context);

            return ['locked' => true];
        }

        $handler = $this->handlers->for($step);
        $result = $handler->handle($payload, $context);

        DB::transaction(function () use ($step, $payload, $context) {
            $row = PlatformSetup::query()->lockForUpdate()->firstOrFail();
            $completed = $row->completed_steps->getArrayCopy();
            $completed[$step->value] = [
                'completed_at' => now()->toIso8601String(),
                'actor' => $context->actor?->id,
                'checksum' => hash('sha256', json_encode(self::redact($payload))),
                'headless' => $context->headless,
            ];
            $next = $step->next();
            $row->forceFill([
                'completed_steps' => $completed,
                'current_step' => $next !== null && $next->order() > ($row->current_step?->order() ?? 0) ? $next : $row->current_step,
                'state' => $row->state === PlatformSetupState::NOT_STARTED ? PlatformSetupState::IN_PROGRESS : $row->state,
            ])->save();
            $this->audit->record($context->actor, 'platform.setup.step_completed', 'platform_setup', $row->id, null,
                ['step' => $step->value], null, ['headless' => $context->headless, 'payload_checksum' => $completed[$step->value]['checksum']],
                $context->actor === null ? AuditActorType::SETUP : AuditActorType::ACCOUNT);
        });
        $this->state->refresh();

        return $result;
    }

    public function skip(PlatformSetupStep $step, SetupContext $context): void
    {
        if (! $step->isOptional()) {
            throw DomainException::of(ErrorCode::SETUP_STEP_ORDER, "The `{$step->value}` step is mandatory.");
        }
        $this->complete($step, ['skipped' => true], $context);
    }

    public function lock(SetupContext $context): void
    {
        $preflight = $this->preflight->run();
        if (Preflight::hasFailures($preflight)) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Environment preflight has failures; resolve them before launching.',
                ['failures' => array_values(array_filter($preflight, fn ($r) => $r['status'] === 'fail'))]);
        }
        DB::transaction(function () use ($context) {
            $row = PlatformSetup::query()->lockForUpdate()->firstOrFail();
            $missing = [];
            foreach (PlatformSetupStep::cases() as $step) {
                if ($step !== PlatformSetupStep::REVIEW_LOCK && ! $step->isOptional() && ! $row->isStepCompleted($step)) {
                    $missing[] = $step->value;
                }
            }
            if ($missing !== []) {
                throw DomainException::of(ErrorCode::SETUP_STEP_ORDER, 'Some mandatory setup steps are incomplete.', ['missing_steps' => $missing]);
            }
            $completed = $row->completed_steps->getArrayCopy();
            $completed[PlatformSetupStep::REVIEW_LOCK->value] = ['completed_at' => now()->toIso8601String(), 'actor' => $context->actor?->id, 'checksum' => ''];
            $row->forceFill([
                'state' => PlatformSetupState::COMPLETED,
                'completed_steps' => $completed,
                'completed_at' => $row->completed_at ?? now(),
                'completed_by_account_id' => $row->completed_by_account_id ?? $context->actor?->id,
                'locked_at' => now(),
                'bootstrap_token_hash' => null,
                'bootstrap_token_expires_at' => null,
            ])->save();
            $this->audit->record($context->actor, 'platform.setup.completed', 'platform_setup', $row->id, null, ['deployment_id' => $row->deployment_id]);
            $this->outbox->publish('platform.setup.completed', 'platform_setup', $row->id, ['deployment_id' => $row->deployment_id]);
        });
        $this->state->refresh();
    }

    public function reopen(UserAccount $actor): void
    {
        if (! app()->isDownForMaintenance()) {
            throw DomainException::of(ErrorCode::POLICY_VIOLATION, 'Put the platform in maintenance mode (php artisan down --secret=…) before reopening setup.');
        }
        if (! $this->access->can($actor, 'platform.setup.reopen')) {
            throw DomainException::permissionDenied('platform.setup.reopen');
        }
        DB::transaction(function () use ($actor) {
            $row = PlatformSetup::query()->lockForUpdate()->firstOrFail();
            $row->state->assertCanTransitionTo(PlatformSetupState::REOPENED, 'platform setup');
            $row->forceFill(['state' => PlatformSetupState::REOPENED, 'reopened_at' => now()])->save();
            $this->audit->record($actor, 'platform.setup.reopened', 'platform_setup', $row->id);
        });
        $this->state->refresh();
    }

    /** @return array<string, mixed> */
    public function overview(): array
    {
        $row = $this->current();

        return [
            'state' => $row->state->value,
            'current_step' => $row->current_step?->value,
            'deployment_id' => $row->deployment_id,
            'steps' => array_map(fn (PlatformSetupStep $s) => [
                'step' => $s->value,
                'label' => $s->label(),
                'order' => $s->order() + 1,
                'optional' => $s->isOptional(),
                'completed' => $row->isStepCompleted($s),
                'completed_at' => $row->completed_steps[$s->value]['completed_at'] ?? null,
            ], PlatformSetupStep::cases()),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function redact(array $payload): array
    {
        foreach ($payload as $k => $v) {
            if (is_array($v)) {
                $payload[$k] = self::redact($v);
            } elseif (is_string($k) && preg_match('/password|secret|token|mfa_code/i', $k)) {
                $payload[$k] = '***';
            }
        }

        return $payload;
    }
}
