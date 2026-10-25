<?php

/*
 * ValidatesSettings (#491) on the admin gateway save, with a test double, so it runs without the PaymentsNepal module.
 */

use App\Contracts\Billing\GatewayRegistry;
use App\Contracts\Integrations\ValidatesSettings;
use App\Modules\Billing\Models\Gateway;
use App\Support\Billing\AbstractGatewayDriver;
use Illuminate\Testing\TestResponse;

require_once __DIR__.'/helpers.php';

/** A driver that refuses a non-numeric account number and records what it was asked to check. */
class SettingsCheckingDriver extends AbstractGatewayDriver implements ValidatesSettings
{
    /** @var list<array<string, string>> */
    public static array $checked = [];

    public function key(): string
    {
        return 'checked_rail';
    }

    public function name(): string
    {
        return 'Checked rail';
    }

    public function capabilities(): array
    {
        return [];
    }

    public function settingsFields(): array
    {
        return [
            ['key' => 'account', 'label' => 'Account number', 'type' => 'string'],
            ['key' => 'label', 'label' => 'Label', 'type' => 'string'],
            ['key' => 'api_secret', 'label' => 'API secret', 'type' => 'secret'],
        ];
    }

    public function validateSettings(array $settings): array
    {
        self::$checked[] = $settings;

        return isset($settings['account']) && ! ctype_digit($settings['account']) ? ['account' => 'The account number is digits only.'] : [];
    }
}

beforeEach(function () {
    SettingsCheckingDriver::$checked = [];
    app(GatewayRegistry::class)->register(new SettingsCheckingDriver);
    actingAsStaff();
    confirmPassword();
});

function saveCheckedRail(array $settings): TestResponse
{
    return test()->put(route('admin.billing.gateways.update', 'checked_rail'), [
        'display_name' => 'Checked rail', 'is_active' => false, 'show_on_order_form' => false, 'settings' => $settings,
    ]);
}

test('a value the driver refuses is shown at its field and nothing is saved', function () {
    saveCheckedRail(['account' => '12-AB', 'label' => '', 'api_secret' => 'never-passed-secret-value'])
        ->assertSessionHasErrors(['settings.account' => 'The account number is digits only.']);

    expect(Gateway::query()->where('key', 'checked_rail')->exists())->toBeFalse()
        // Trimmed, non-secret and non-empty values only.
        ->and(SettingsCheckingDriver::$checked)->toBe([['account' => '12-AB']]);
});

test('values the driver accepts are saved', function () {
    saveCheckedRail(['account' => ' 1234 ', 'label' => 'Main'])->assertSessionHasNoErrors();

    expect(Gateway::query()->where('key', 'checked_rail')->sole()->setting('account'))->toBe('1234')
        ->and(SettingsCheckingDriver::$checked)->toBe([['account' => '1234', 'label' => 'Main']]);
});
