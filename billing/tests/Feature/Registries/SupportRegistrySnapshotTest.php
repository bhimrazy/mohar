<?php

use Tests\Support\Registries\RegistrySnapshot;

test('the Support module registers the same permissions, settings, navigation, dashboard, webhooks, merge fields and listeners as recorded', function () {
    RegistrySnapshot::assertMatchesFixture(
        'support',
        '/(^|[\/.])(support|tickets?)([.\/-]|_inbound|_reply|$)/',
        'App\Modules\Support',
    );
});
