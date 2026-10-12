<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domains\Audit\Services\AuditRecorder;
use App\Domains\IdentityAccess\Enums\AccountStatus;
use App\Domains\IdentityAccess\Enums\SecurityEventType;
use App\Domains\IdentityAccess\Models\SecurityEvent;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Permissions\PermissionRegistry;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\TotpService;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Enums\BrandAssetKind;
use App\Domains\Platform\Http\Middleware\EnsureSetupSession;
use App\Domains\Platform\Enums\PlatformSetupState;
use App\Domains\Platform\Enums\PlatformSetupStep;
use App\Domains\Platform\Services\BrandKitService;
use App\Domains\Platform\Services\BrandResolver;
use App\Domains\Platform\Services\ConfigBundleService;
use App\Domains\Platform\Services\Preflight;
use App\Domains\Platform\Setup\SetupContext;
use App\Domains\Platform\Setup\SetupHandlers;
use App\Domains\Platform\Setup\SetupService;
use App\Domains\Platform\Setup\Steps\SuperAdminStep;
use App\Support\Enums\EnumRegistry;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use App\Support\Schema\SchemaRegistry;
use App\Support\Validation\ValidationErrorCode;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Platform Setup wizard (Chapter 02 §2.3–2.5, Chapter 12 §12.5). A single-use bootstrap token is
 * exchanged for a *setup session* (not a user session) valid only for /setup/*; after step 2 the
 * setup session also signs the new Super Admin in. A setup resumed in a new session (expired, other
 * browser, password changed) signs the existing Super Admin in again through signIn(). Reopened setup
 * is entered by a signed-in holder of platform.setup.reopen. Every step runs through SetupService (ordering, audit, idempotency).
 */
final class SetupController
{
    private const SESSION_DEPLOYMENT = EnsureSetupSession::SESSION_DEPLOYMENT;

    private const SESSION_ADMIN = 'setup.super_admin_id';

    private const SESSION_SCRATCH = 'setup.scratch';

    public function __construct(private readonly SetupService $setup) {}

    public function index(Request $request): InertiaResponse|RedirectResponse
    {
        if ($request->filled('token')) {
            $this->authorize($request, (string) $request->query('token'));

            return redirect()->route('setup.index');   // drop the token from the address bar
        }

        return Inertia::render('Setup/Wizard', ['setup' => $this->state($request)]);
    }

    /** Token exchange for API-style clients: {token}. */
    public function session(Request $request): JsonResponse
    {
        $this->authorize($request, (string) $request->input('token'));

        return response()->json(['data' => $this->state($request)]);
    }

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->state($request)]);
    }

    /**
     * Resume setup as the existing Super Admin: same checks as normal sign-in — lockout after repeated
     * failures, active account, authenticator code when enrolled — inside an authorized setup session.
     */
    public function signIn(Request $request, PlatformConfig $config, TotpService $totp): JsonResponse
    {
        $identifier = mb_strtolower(trim((string) $request->input('identifier', '')));
        $password = (string) $request->input('password', '');
        if ($identifier === '' || $password === '') {
            throw ValidationException::withMessages(['password' => [ValidationErrorCode::CUSTOM->message('password', [], 'Enter the Super Admin username or email and password.')]]);
        }
        $invalid = fn () => ValidationException::withMessages(['password' => [ValidationErrorCode::CUSTOM->message('password', [], 'The username or password is incorrect.')]]);
        $account = UserAccount::query()->where('is_super_admin', true)
            ->where(fn ($q) => $q->whereRaw('lower(username) = ?', [$identifier])->orWhereRaw('lower(email) = ?', [$identifier]))->first();
        if ($account === null) {
            Hash::check($password, '$argon2id$v=19$m=65536,t=4,p=1$c29tZXNhbHQ$5Bd5V7m6/5qY9dzUvbKXHA');   // equalize timing
            SecurityEvent::log(SecurityEventType::LOGIN_FAILURE, null, $identifier, ['context' => 'setup']);
            throw $invalid();
        }
        if ($account->locked_until !== null && $account->locked_until->isFuture()) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Too many failed attempts. Try again in a few minutes.');
        }
        if (! Hash::check($password, (string) $account->password_hash)) {
            $failures = $account->failed_login_count + 1;
            $lock = $failures >= $config->int('identity.lockout.threshold');
            $account->forceFill(['failed_login_count' => $lock ? 0 : $failures,
                'locked_until' => $lock ? now()->addMinutes($config->int('identity.lockout.duration_minutes')) : $account->locked_until])->save();
            SecurityEvent::log($lock ? SecurityEventType::LOCKOUT : SecurityEventType::LOGIN_FAILURE, $account->id, $identifier, ['context' => 'setup']);
            throw $invalid();
        }
        if ($account->account_status !== AccountStatus::ACTIVE) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'The Super Admin account is not active.');
        }
        if ($totp->isEnrolled($account)) {
            $code = trim((string) $request->input('code', ''));
            if ($code === '') {
                throw ValidationException::withMessages(['code' => [ValidationErrorCode::CUSTOM->message('code', [], 'Enter the 6-digit code from your authenticator app (or a recovery code).')]]);
            }
            if (! $totp->verify($account, $code)) {
                SecurityEvent::log(SecurityEventType::LOGIN_FAILURE, $account->id, null, ['context' => 'setup', 'reason' => 'mfa']);
                throw ValidationException::withMessages(['code' => [ValidationErrorCode::CUSTOM->message('code', [], 'The verification code is incorrect.')]]);
            }
        }

        $account->forceFill(['failed_login_count' => 0, 'last_login_at' => now(), 'last_login_ip' => $request->ip()])->save();
        $deployment = $request->session()->get(self::SESSION_DEPLOYMENT);
        $scratch = $request->session()->get(self::SESSION_SCRATCH, []);
        Auth::guard('web')->login($account);
        $request->session()->regenerate();
        $request->session()->put([self::SESSION_DEPLOYMENT => $deployment, self::SESSION_ADMIN => $account->id, self::SESSION_SCRATCH => $scratch]);
        SecurityEvent::log(SecurityEventType::LOGIN_SUCCESS, $account->id, null, ['context' => 'setup']);
        app(AuditRecorder::class)->record($account, 'platform.setup.signed_in', 'platform_setup', $this->setup->current()->id);

        return response()->json(['data' => $this->state($request)]);
    }

    public function step(string $step, SetupHandlers $handlers): JsonResponse
    {
        $s = $this->resolveStep($step);

        return response()->json(['data' => ['step' => $s->value, 'label' => $s->label(), 'optional' => $s->isOptional(),
            'current' => $s === PlatformSetupStep::REVIEW_LOCK ? $this->setup->overview() : $handlers->for($s)->current()]]);
    }

    public function complete(Request $request, string $step): JsonResponse
    {
        $s = $this->resolveStep($step);
        $context = $this->context($request);
        $result = $this->setup->complete($s, (array) $request->json()->all() ?: $request->except(['_token']), $context);
        $this->remember($request, $context, $s, $result);

        return response()->json(['data' => ['result' => $result] + $this->state($request)]);
    }

    public function skip(Request $request, string $step): JsonResponse
    {
        $context = $this->context($request);
        $this->setup->skip($this->resolveStep($step), $context);
        $this->remember($request, $context);

        return response()->json(['data' => $this->state($request)]);
    }

    /** TOTP secret for the Super Admin being created (kept in the setup session only). */
    public function mfaSecret(Request $request, BrandResolver $brand): JsonResponse
    {
        $context = $this->context($request);
        $email = (string) $request->input('email', 'admin');
        $secret = SuperAdminStep::issueSecret($context, $email, (string) $brand->publicProfile()['app_name']);
        $this->remember($request, $context);
        $qr = (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($secret['otpauth_uri']);

        return response()->json(['data' => $secret + ['qr_svg' => $qr]]);
    }

    public function brandAsset(Request $request, BrandKitService $kits): JsonResponse
    {
        $kind = BrandAssetKind::tryFrom((string) $request->input('kind'));
        if ($kind === null || $kind === BrandAssetKind::DERIVED_ICON) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Choose the asset kind.', ['field' => 'kind']);
        }
        $file = $request->file('file') ?? throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Attach the image.', ['field' => 'file']);

        return response()->json(['data' => ['upload_id' => $kits->storeUpload($file, $kind), 'kind' => $kind->value]], 201);
    }

    public function bundle(Request $request, ConfigBundleService $bundles): JsonResponse
    {
        return response()->json($bundles->export($this->context($request)->actor), 200,
            ['Content-Disposition' => 'attachment; filename="bundle-'.now()->format('Ymd-His').'.json"']);
    }

    /** Metadata the wizard needs before `/api/v1/meta/*` opens (setup session only). */
    public function metaEnums(EnumRegistry $enums): JsonResponse
    {
        return response()->json(['data' => $enums->export()]);
    }

    public function metaSchema(string $name, SchemaRegistry $schemas): JsonResponse
    {
        $class = $schemas->all()->get($name) ?? throw DomainException::notFound('Schema');

        return response()->json(['data' => $class::toArray()]);
    }

    public function metaPermissions(PermissionRegistry $registry): JsonResponse
    {
        return response()->json(['data' => ['modules' => $registry->modules(), 'permissions' => $registry->all()->map(fn ($p) => $p->toArray())->values()->all()]]);
    }

    public function lock(Request $request): JsonResponse
    {
        $this->setup->complete(PlatformSetupStep::REVIEW_LOCK, [], $this->context($request));
        $request->session()->forget([self::SESSION_DEPLOYMENT, self::SESSION_SCRATCH, self::SESSION_ADMIN]);
        $request->session()->regenerate();

        return response()->json(['data' => ['locked' => true, 'redirect' => url('/')]]);
    }

    // ---------------------------------------------------------------------------------------

    private function authorize(Request $request, string $token): void
    {
        $row = $this->setup->exchange($token);
        $request->session()->regenerate();
        $request->session()->put(self::SESSION_DEPLOYMENT, $row->deployment_id);
    }

    private function context(Request $request): SetupContext
    {
        $adminId = $request->session()->get(self::SESSION_ADMIN);
        $actor = is_string($adminId) ? UserAccount::query()->find($adminId) : null;
        $actor ??= Auth::guard('web')->user() instanceof UserAccount && Auth::guard('web')->user()->is_super_admin ? Auth::guard('web')->user() : null;

        return new SetupContext($actor, headless: false, session: (array) $request->session()->get(self::SESSION_SCRATCH, []));
    }

    private function remember(Request $request, SetupContext $context, ?PlatformSetupStep $step = null, array $result = []): void
    {
        $request->session()->put(self::SESSION_SCRATCH, $context->session);
        if ($step === PlatformSetupStep::SUPER_ADMIN && isset($result['account_id'])) {
            $request->session()->put(self::SESSION_ADMIN, $result['account_id']);
            Auth::guard('web')->login(UserAccount::query()->findOrFail($result['account_id']));
            $deployment = $request->session()->get(self::SESSION_DEPLOYMENT);
            $request->session()->regenerate();
            $request->session()->put(self::SESSION_DEPLOYMENT, $deployment);
            $request->session()->put(self::SESSION_ADMIN, $result['account_id']);
            $request->session()->put(self::SESSION_SCRATCH, array_diff_key($context->session, [SuperAdminStep::SESSION_SECRET => 1]));
        }
        if ($step === PlatformSetupStep::IDENTITY_SECURITY && ($result['password_rotated'] ?? false) === true && $context->actor !== null) {
            // AuthenticateSession signs a session out when the account's password hash no longer matches the
            // one it stored; record the new hash so changing the password here does not end this setup session.
            $fresh = UserAccount::query()->findOrFail($context->actor->id);
            if (Auth::guard('web')->check()) {
                Auth::guard('web')->setUser($fresh);
            }
            $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $fresh->getAuthPassword());
        }
    }

    private function resolveStep(string $step): PlatformSetupStep
    {
        return PlatformSetupStep::tryFrom($step) ?? throw DomainException::of(ErrorCode::NOT_FOUND, "Unknown setup step `$step`.",
            ['allowed' => PlatformSetupStep::values()]);
    }

    /** @return array<string, mixed> */
    private function state(Request $request): array
    {
        $row = $this->setup->current();

        return $this->setup->overview() + [
            'authorized' => in_array($row->state, [PlatformSetupState::IN_PROGRESS, PlatformSetupState::REOPENED], true)
                && EnsureSetupSession::authorized($request, $row->deployment_id, $row->state, app(Access::class)),
            'super_admin_signed_in' => $this->context($request)->actor !== null,
            'preflight' => app(Preflight::class)->run(),
        ];
    }
}
