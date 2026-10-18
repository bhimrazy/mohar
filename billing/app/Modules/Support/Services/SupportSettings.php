<?php

namespace App\Modules\Support\Services;

use App\Modules\Core\Support\Settings;

/**
 * Typed accessors over the "support.*" settings (DB override, else Config/support.php).
 */
class SupportSettings
{
    /** Never accepted, whatever the allow-lists say (spec §4.3). */
    public const HARD_DENY = ['php', 'phtml', 'phar', 'exe', 'bat', 'cmd', 'sh', 'js', 'html', 'htm', 'svg'];

    public function __construct(private Settings $settings) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->settings->get('support.'.$key, $default);
    }

    public function int(string $key, int $default = 0): int
    {
        $value = $this->get($key, $default);

        return is_numeric($value) ? (int) $value : $default;
    }

    public function bool(string $key, bool $default = false): bool
    {
        return filter_var($this->get($key, $default), FILTER_VALIDATE_BOOL);
    }

    public function string(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * A comma-separated (or array) setting as a lowercase list.
     *
     * @return list<string>
     */
    public function list(string $key): array
    {
        $value = $this->get($key, '');
        $items = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $item): string => strtolower(trim(is_scalar($item) ? (string) $item : '')),
            $items,
        ))));
    }

    /**
     * @return list<string>
     */
    public function allowedExtensions(bool $staff): array
    {
        return array_values(array_diff(
            $this->list($staff ? 'staff_attachment_extensions' : 'attachment_extensions'),
            self::HARD_DENY,
        ));
    }

    /**
     * @return list<string>
     */
    public function autoCloseStatuses(): array
    {
        return $this->list('auto_close_statuses');
    }

    /**
     * @return list<string>
     */
    public function awaitingReplyStatuses(): array
    {
        return $this->list('awaiting_reply_statuses');
    }
}
