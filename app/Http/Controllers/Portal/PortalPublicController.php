<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domains\CustomerPortal\Services\InvitationService;
use App\Domains\CustomerPortal\Services\PortalCatalog;
use App\Domains\CustomerPortal\Services\RegistrationService;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Services\BrandResolver;
use App\Http\Controllers\Api\ApiController;
use App\Support\Exceptions\DomainException;
use App\Support\Exceptions\ErrorCode;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Public Customer Portal endpoints (no account yet): landing information, registration, e-mail
 * verification, invitations and two-step verification set-up. Only explicitly public information is
 * returned — never prices, stock, orders or account data.
 */
final class PortalPublicController extends ApiController
{
    private const SESSION = 'portal.registration';

    public function __construct(
        private readonly PlatformConfig $config,
        private readonly RegistrationService $registrations,
        private readonly InvitationService $invitations,
        private readonly PortalCatalog $catalog,
        private readonly BrandResolver $brand,
    ) {}

    public function info(): JsonResponse
    {
        $brand = $this->brand->publicProfile();
        $company = (array) (DB::table('company_profile')->first(['phone', 'email', 'website', 'operational_address']) ?? []);
        $address = isset($company['operational_address']) ? (array) json_decode((string) $company['operational_address'], true) : [];

        return response()->json(['data' => [
            'company_name' => $brand['company_name'],
            'tagline' => $brand['tagline'],
            'registration_mode' => $this->config->get('portal.registration.mode'),
            'direct_ordering' => $this->config->bool('portal.orders.direct_ordering'),
            'categories' => $this->config->bool('portal.catalog.public_preview')
                ? array_map(fn (array $c) => ['name' => $c['name'], 'item_count' => (int) $c['item_count']], $this->catalog->categories()) : [],
            'contact' => array_filter([
                'phone' => $brand['support_phone'] ?? ($company['phone'] ?? null), 'email' => $brand['support_email'] ?? ($company['email'] ?? null),
                'website' => $brand['support_url'] ?? ($company['website'] ?? null),
                'city' => $address['city'] ?? null,
            ]),
        ]]);
    }

    public function register(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        if (($data['accept_terms'] ?? false) !== true) {
            throw DomainException::of(ErrorCode::VALIDATION_FAILED, 'Accept the terms to continue.', ['field' => 'accept_terms']);
        }
        $this->registrations->register($data, $request->ip());

        // Same answer whether or not the address already had an account.
        return response()->json(['data' => ['status' => 'check_your_email',
            'message' => 'We sent a confirmation link to '.mb_strtolower(trim((string) $data['email'])).'. Open it to continue.']], 202);
    }

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $registration = $this->registrations->verifyEmail((string) $data['registration'], (string) $data['token']);
        $request->session()->put(self::SESSION, ['id' => $registration->id, 'at' => time()]);

        return response()->json(['data' => ['status' => $registration->status->value, 'company_name' => $registration->company_name, 'email' => $registration->email]]);
    }

    public function invitation(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->invitations->preview((string) $request->query('invitation'), (string) $request->query('token'))]);
    }

    public function acceptInvitation(Request $request): JsonResponse
    {
        $data = $this->payload($request);
        $registration = $this->invitations->accept((string) $data['invitation'], (string) $data['token'], $data, $request->ip());
        $request->session()->put(self::SESSION, ['id' => $registration->id, 'at' => time()]);

        return response()->json(['data' => ['status' => $registration->status->value, 'company_name' => $registration->company_name, 'email' => $registration->email]], 201);
    }

    public function beginSecurity(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->registrations->beginSecurity($this->pending($request))]);
    }

    public function confirmSecurity(Request $request): JsonResponse
    {
        $result = $this->registrations->confirmSecurity($this->pending($request), (string) ($this->payload($request)['code'] ?? ''));
        $request->session()->forget(self::SESSION);

        return response()->json(['data' => $result + ['message' => $result['status'] === 'approved'
            ? 'Your account is ready. Sign in with your email, password and authenticator code.'
            : 'Thank you. We are reviewing your registration and will e-mail you when your account is ready.']]);
    }

    /** The registration being completed in this browser (set by the e-mail or invitation link). */
    private function pending(Request $request): string
    {
        $pending = $request->session()->get(self::SESSION);
        if (! is_array($pending) || time() - (int) $pending['at'] > 3600) {
            throw DomainException::of(ErrorCode::UNAUTHENTICATED, 'Open the link from your e-mail again to continue.');
        }

        return (string) $pending['id'];
    }
}
