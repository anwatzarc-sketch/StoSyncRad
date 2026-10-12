<?php

declare(strict_types=1);

namespace App\Http\Controllers\Portal;

use App\Domains\CustomerPortal\Services\CustomerContext;
use App\Domains\CustomerPortal\Services\InvitationService;
use App\Domains\CustomerPortal\Services\MembershipService;
use App\Http\Controllers\Api\ApiController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The customer's own portal team: everyone may see their colleagues; the customer's administrators
 * (customer.member.manage) invite, promote and remove them. Every action is limited to the signed-in
 * account's own customer — another customer's people are "not found".
 */
final class PortalTeamController extends ApiController
{
    public function __construct(private readonly CustomerContext $context, private readonly MembershipService $membership) {}

    public function index(Request $request): JsonResponse
    {
        $account = $this->actor($request);
        $customer = $this->context->partnerFor($account);

        return response()->json(['data' => $this->membership->team($customer, $account) + ['can_manage' => $this->membership->isAdmin($account)]]);
    }

    public function invite(Request $request, InvitationService $invitations): JsonResponse
    {
        $account = $this->actor($request);
        $customer = $this->context->partnerFor($account);
        $invitation = $invitations->create(['partner_id' => $customer->id] + $this->payload($request), $account);

        return response()->json(['data' => ['id' => $invitation->id, 'email' => $invitation->email, 'expires_at' => $invitation->expires_at->toIso8601String()]], 201);
    }

    public function revokeInvitation(Request $request, InvitationService $invitations, string $id): JsonResponse
    {
        $account = $this->actor($request);
        $this->context->partnerFor($account);
        $invitations->revoke($id, $account);

        return response()->json(['data' => ['status' => 'revoked']]);
    }

    public function role(Request $request, string $id): JsonResponse
    {
        $account = $this->actor($request);
        $this->context->partnerFor($account);
        $member = $this->membership->setAdministrator($id, (bool) $this->payload($request)['administrator'], $account);

        return response()->json(['data' => ['id' => $member->id, 'administrator' => $this->membership->isAdmin($member)]]);
    }

    public function deactivate(Request $request, string $id): JsonResponse
    {
        $account = $this->actor($request);
        $this->context->partnerFor($account);
        $member = $this->membership->deactivate($id, $account);

        return response()->json(['data' => ['id' => $member->id, 'status' => $member->account_status->value]]);
    }

    public function reactivate(Request $request, string $id): JsonResponse
    {
        $account = $this->actor($request);
        $this->context->partnerFor($account);
        $member = $this->membership->reactivate($id, $account);

        return response()->json(['data' => ['id' => $member->id, 'status' => $member->account_status->value]]);
    }
}
