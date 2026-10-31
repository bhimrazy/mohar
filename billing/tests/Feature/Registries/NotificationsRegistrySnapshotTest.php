<?php

use Tests\Support\Registries\RegistrySnapshot;

test('the Notifications module registers the same permissions, settings, navigation, dashboard, listeners and reference data as recorded', function () {
    RegistrySnapshot::assertMatchesFixture(
        'notifications',
        '/(^|[\/.\s"_-])(notifications|email_delivery)([.\/\s"_-]|$)/',
        'App\Modules\Notifications',
    );
});
