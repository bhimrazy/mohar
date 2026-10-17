<?php

namespace App\Support\Navigation;

final readonly class NavItem
{
    /**
     * @param  string  $route  route name
     * @param  string  $icon  lucide-react icon name, e.g. "FileText"
     * @param  string|null  $permission  staff permission required (admin area only)
     * @param  string|null  $activePattern  route name pattern marking the item active, e.g. "admin.billing.*"
     */
    public function __construct(
        public string $title,
        public string $route,
        public string $icon = 'Circle',
        public string $group = 'General',
        public int $order = 100,
        public ?string $permission = null,
        public ?string $activePattern = null,
    ) {}
}
