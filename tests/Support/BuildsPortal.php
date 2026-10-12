<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domains\Catalog\Models\Item;
use App\Domains\CustomerPortal\Services\MembershipService;
use App\Domains\IdentityAccess\Actions\CreateUserAccountAction;
use App\Domains\IdentityAccess\Enums\AccountType;
use App\Domains\IdentityAccess\Enums\PersonStatus;
use App\Domains\IdentityAccess\Models\MfaCredential;
use App\Domains\IdentityAccess\Models\Person;
use App\Domains\IdentityAccess\Models\UserAccount;
use App\Domains\IdentityAccess\Services\Access;
use App\Domains\IdentityAccess\Services\TotpService;
use App\Domains\Organization\Models\Warehouse;
use App\Domains\Partners\Actions\SavePartnerAction;
use App\Domains\Partners\Models\Partner;
use App\Domains\Platform\Config\PlatformConfig;
use App\Domains\Platform\Seeds\DocumentSequencesSeed;
use App\Domains\Platform\Seeds\SeedRunner;
use App\Domains\Platform\Seeds\TemplatesSeed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

/** Customer Portal fixtures: the portal switched on, customers with members, published items. */
trait BuildsPortal
{
    protected const CUSTOMER_PASSWORD = 'Correct-Horse-Battery-9!';

    protected function enablePortal(Warehouse $warehouse): void
    {
        foreach ([DocumentSequencesSeed::class, TemplatesSeed::class] as $seed) {
            app(SeedRunner::class)->run($seed);
        }
        $this->setConfig('feature.customer_portal', true);
        $this->setConfig('portal.orders.warehouse_id', $warehouse->id);
        $this->setConfig('locale.currency.base', 'ETB');
        app(PlatformConfig::class)->forget();
        $this->syncPermissions();
    }

    protected function customerPartner(string $name = 'Acme Pharmacy'): Partner
    {
        return app(SavePartnerAction::class)->execute(['code' => 'C'.Str::upper(Str::random(6)), 'name' => $name, 'partner_types' => ['customer'],
            'default_currency' => 'ETB', 'email' => Str::lower(Str::random(6)).'@acme.example'], null);
    }

    /** An active portal member of the customer, with two-step verification enrolled. */
    protected function customerMember(Partner $partner): UserAccount
    {
        $email = 'buyer.'.Str::lower(Str::random(8)).'@acme.example';
        $person = new Person(['full_name' => 'Buyer '.Str::random(4), 'email' => $email]);
        $person->forceFill(['status' => PersonStatus::ACTIVE])->save();
        $account = app(CreateUserAccountAction::class)->execute(['person_id' => $person->id, 'username' => $email, 'email' => $email,
            'password' => self::CUSTOMER_PASSWORD, 'account_type' => AccountType::CUSTOMER->value], null, activate: false, portal: true, customerPartnerId: $partner->id);
        $this->enrollTotp($account);
        app(MembershipService::class)->activate($account, $partner, null, 'Test member');
        app(Access::class)->forget();

        return $account->refresh();
    }

    protected function enrollTotp(UserAccount $account): void
    {
        $secret = app(TotpService::class)->beginEnrollment($account)['secret'];
        app(TotpService::class)->confirmEnrollment($account, (new Google2FA)->getCurrentOtp($secret));
        // Allow the next code in the same 30-second step (tests run faster than authenticators).
        MfaCredential::query()->where('user_account_id', $account->id)->update(['last_used_counter' => null]);
    }

    protected function totpCode(UserAccount $account): string
    {
        MfaCredential::query()->where('user_account_id', $account->id)->update(['last_used_counter' => null]);

        return (new Google2FA)->getCurrentOtp((string) MfaCredential::query()->where('user_account_id', $account->id)->value('secret'));
    }

    /** A sellable item published online with a list price (null = must be quoted). */
    protected function onlineItem(?string $price = '25.00', array $extra = []): Item
    {
        $item = $this->item();
        $item->forceFill(['is_sellable' => true, 'is_available_online' => true, 'list_price' => $price, 'list_price_currency' => $price === null ? null : 'ETB',
            'name' => 'Amoxicillin 500 mg capsules'] + $extra)->save();

        return $item->refresh();
    }

    protected function portalRole(bool $admin = false): string
    {
        return (string) DB::table('roles')->where('code', $admin ? '__customer_admin' : '__customer_member')->value('id');
    }
}
