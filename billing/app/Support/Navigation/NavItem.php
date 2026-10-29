<?php

namespace App\Support\Navigation;

use App\Models\User;

/**
 * One entry in a Navigation menu.
 *
 * @api
 */
final readonly class NavItem
{
    /**
     * @param  string  $route  route name
     * @param  string  $icon  lucide-react icon name, e.g. "FileText"
     * @param  string|null  $permission  staff permission required (admin area only)
     * @param  string|null  $activePattern  route name pattern marking the item active, e.g. "admin.billing.*"
     * @param  (\Closure(User|null): (int|null))|null  $badge  count shown next to the item (e.g. tickets awaiting reply); keep it a cheap indexed count
     */
    public function __construct(
        public string $title,
        public string $route,
        public string $icon = 'Circle',
        public string $group = 'General',
        public int $order = 100,
        public ?string $permission = null,
        public ?string $activePattern = null,
        public ?\Closure $badge = null,
    ) {}
}
