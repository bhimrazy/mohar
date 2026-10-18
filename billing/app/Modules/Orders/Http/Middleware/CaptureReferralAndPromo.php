<?php

namespace App\Modules\Orders\Http\Middleware;

use App\Modules\Core\Support\Settings;
use App\Modules\Orders\Models\Promotion;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Symfony\Component\HttpFoundation\Response;

/**
 * On any GET page: `?ref=CODE` stores the referral code in a cookie (passed on OrderPlaced for
 * Affiliates); `?promo=CODE` remembers a promotion code that the next cart write applies.
 */
class CaptureReferralAndPromo
{
    public const SESSION_PROMO = 'orders.pending_promo';

    private const CODE_PATTERN = '/^[A-Za-z0-9_-]{1,50}$/';

    public function __construct(private Settings $settings) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET')) {
            $referral = $request->query('ref');

            if (is_string($referral) && preg_match(self::CODE_PATTERN, $referral)) {
                Cookie::queue(
                    (string) $this->settings->get('orders.referral_cookie', 'orders_ref'),
                    $referral,
                    (int) $this->settings->get('orders.referral_cookie_days', 90) * 24 * 60,
                );
            }

            $promo = $request->query('promo');

            if (is_string($promo) && preg_match(self::CODE_PATTERN, $promo) && $request->hasSession()) {
                $request->session()->put(self::SESSION_PROMO, Promotion::normalizeCode($promo));
            }
        }

        return $next($request);
    }

    public static function referralFrom(Request $request): ?string
    {
        $value = $request->cookie((string) config('orders.referral_cookie', 'orders_ref'));

        return is_string($value) && preg_match(self::CODE_PATTERN, $value) ? $value : null;
    }
}
