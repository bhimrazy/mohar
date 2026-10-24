<?php

use App\Modules\Core\Models\Currency;
use Inertia\Testing\AssertableInertia as Assert;

it('orders the Setup group from configuration to logs', function () {
    Currency::factory()->create();
    actingAsStaff(['*']);

    $this->get(route('admin.platform.currencies.index'))->assertInertia(fn (Assert $page) => $page->where('navigation', function ($groups) {
        $titles = array_column(collect($groups)->firstWhere('title', 'Setup')['items'], 'title');

        expect(array_slice($titles, 0, 3))->toBe(['Settings', 'Appearance', 'Website'])
            ->and(array_slice($titles, -3))->toBe(['Automation', 'Email log', 'Gateway log'])
            ->and(array_search('Staff', $titles, true))->toBeLessThan(array_search('Products', $titles, true));

        return true;
    }));
});
