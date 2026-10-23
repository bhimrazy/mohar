<?php

namespace App\Modules\Core\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property int $user_id
 * @property string $notice
 * @property Carbon|null $dismissed_at
 */
#[Fillable(['user_id', 'notice', 'dismissed_at'])]
class StaffNotice extends Model
{
    /** Added in the upgrade that made the automation times company time (#337). */
    public const COMPANY_TIME_SETTINGS = 'company_time_settings';

    /**
     * Who may see and dismiss each notice: a permission per notice key. A key that is not here does not exist.
     *
     * @var array<string, string>
     */
    private const AUDIENCE = [
        self::COMPANY_TIME_SETTINGS => 'core.settings.update',
    ];

    public static function exists(string $notice): bool
    {
        return isset(self::AUDIENCE[$notice]);
    }

    /** The permission a staff member needs to see and dismiss the notice. */
    public static function permissionFor(string $notice): ?string
    {
        return self::AUDIENCE[$notice] ?? null;
    }

    protected function casts(): array
    {
        return ['dismissed_at' => 'datetime'];
    }
}
