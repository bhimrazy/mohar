<?php

use Tests\Support\Registries\RegistrySnapshot;

test('the Orders module registers the same permissions, settings, navigation, webhooks, automation and listeners as recorded', function () {
    RegistrySnapshot::assertMatchesFixture(
        'orders',
        '/(^|[\/.\s"])(orders?|promotions?|store)([.\/\s"_-]|$)|affiliate\.commission_earned|AffiliateCommissionEarned/',
        'App\Modules\Orders',
    );
});
