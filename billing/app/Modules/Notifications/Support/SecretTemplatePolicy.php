<?php

namespace App\Modules\Notifications\Support;

use App\Modules\Notifications\Services\MergeContext;

/**
 * Rules for templates that carry a one-time secret — reset, verification and invitation links (security
 * review PR #28, B1). Only the wording is editable:
 *
 *  - no images and no raw HTML (an image URL is fetched when the mail opens, leaking whatever it embeds);
 *  - links and bare URLs only to this application's own host (or its system URLs);
 *  - the secret link appears exactly once, as the whole target of the primary action: [Label]({{ auth.reset_url }}).
 *
 * Checked when a template is saved (validation errors) and enforced again on the rendered HTML. "This application"
 * is config('app.url')'s host; at render time the host of the secret link itself is allowed too, so a custom
 * ResetPassword::createUrlUsing() domain keeps working.
 */
final class SecretTemplatePolicy
{
    /** Secret-bearing templates and the one secret link each must offer. */
    public const ACTION_LINKS = [
        'auth.password_reset' => 'auth.reset_url',
        'staff.password_reset' => 'auth.reset_url',
        'auth.email_verification' => 'auth.verify_url',
        'auth.user_invitation' => 'auth.invite_url',
        'staff.invitation' => 'auth.invite_url',
    ];

    /** Non-secret merge fields that always hold this application's own URLs. */
    private const APP_URL_FIELDS = ['system.client_area_url', 'system.admin_url', 'company.url'];

    private const SECRET_FIELD = '\{\{\s*(auth\.(?:reset|verify|invite)_url)\s*(?:\|[^}]*)?\}\}';

    /**
     * @return list<string> human-readable problems; empty when the template is acceptable
     */
    public static function violations(string $templateKey, string $subject, string $body): array
    {
        $problems = [];
        $required = self::ACTION_LINKS[$templateKey] ?? null;

        if (preg_match('/'.self::SECRET_FIELD.'/', $subject)) {
            $problems[] = __('The subject cannot contain the one-time link.');
        }

        if (preg_match('/!\[/', $body)) {
            $problems[] = __('This email carries a one-time link, so it cannot contain images.');
        }

        if (preg_match('/<\s*[a-z!\/]/i', $body)) {
            $problems[] = __('This email carries a one-time link, so it cannot contain HTML.');
        }

        // Every link target and bare URL must point at this application.
        preg_match_all('/\]\(\s*([^)\s]*)\s*\)/', $body, $targets);
        foreach ($targets[1] as $target) {
            if (! self::allowedTarget($target)) {
                $problems[] = __('Links in this email can only point to :host.', ['host' => self::appHost()]);
                break;
            }
        }

        $withoutTargets = (string) preg_replace('/\]\(\s*[^)\s]*\s*\)/', ']()', $body);
        preg_match_all('#(?:https?:)?//[^\s)<>\]"\']+#i', $withoutTargets, $bare);
        foreach ($bare[0] as $url) {
            if (! self::sameHost($url)) {
                $problems[] = __('Links in this email can only point to :host.', ['host' => self::appHost()]);
                break;
            }
        }

        // The secret link: exactly once, as the whole target of one link, and the right one for this email.
        $uses = preg_match_all('/'.self::SECRET_FIELD.'/', $body);
        $asTargets = preg_match_all('/\]\(\s*\{\{\s*(auth\.(?:reset|verify|invite)_url)\s*\}\}\s*\)/', $body, $targetUses);

        if ($uses !== $asTargets) {
            $problems[] = __('The one-time link can only be used as the target of the action link, e.g. [Choose a new password]({{ :field }}).', ['field' => $required ?? 'auth.reset_url']);
        } elseif ($required !== null && ($asTargets !== 1 || $targetUses[1][0] !== $required)) {
            $problems[] = __('Keep exactly one action link to {{ :field }}.', ['field' => $required]);
        }

        return array_values(array_unique($problems));
    }

    /**
     * Defence in depth on rendered HTML: drop images, unwrap links to other hosts, and mask the secret
     * values everywhere except the primary button's href.
     *
     * @param  list<string>  $secretValues
     */
    public static function sanitizeHtml(string $html, array $secretValues): string
    {
        // The secret link's own host counts as ours: a custom ResetPassword::createUrlUsing() may point the
        // reset page at another domain (e.g. a separate client portal).
        $hosts = array_values(array_filter(array_map(fn (string $url): mixed => parse_url($url, PHP_URL_HOST), $secretValues), 'is_string'));

        $html = (string) preg_replace('/<img\b[^>]*>/i', '', $html);
        $html = (string) preg_replace_callback(
            '#<a\b([^>]*)>(.*?)</a>#is',
            function (array $match) use ($hosts): string {
                preg_match('/href="([^"]*)"/i', $match[1], $href);

                return self::sameHost(html_entity_decode($href[1] ?? ''), $hosts) ? $match[0] : $match[2];
            },
            $html,
        );

        $secretValues = array_values(array_filter($secretValues, fn (string $value): bool => $value !== '' && $value !== '••••'));

        if ($secretValues === []) {
            return $html;
        }

        // Protect the primary button's href, then mask every other occurrence of a secret.
        $button = '';
        $html = (string) preg_replace_callback('/<a\b[^>]*class="button"[^>]*>/i', function (array $anchor) use (&$button): string {
            return (string) preg_replace_callback('/href="([^"]*)"/i', function (array $href) use (&$button): string {
                $button = $href[1];

                return 'href="@@PRIMARY_ACTION@@"';
            }, $anchor[0], 1);
        }, $html, 1);

        foreach ($secretValues as $secret) {
            $html = str_replace([$secret, e($secret), rawurlencode($secret), urlencode($secret)], '••••', $html);
        }

        return str_replace('@@PRIMARY_ACTION@@', $button, $html);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return list<string>
     */
    public static function secretValues(array $context): array
    {
        return array_values(array_filter(array_map(
            fn (string $path): mixed => data_get($context, $path),
            MergeContext::SECRET_PATHS,
        ), fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    private static function allowedTarget(string $target): bool
    {
        if (preg_match('/^\{\{\s*([a-z0-9_.]+)\s*\}\}$/', $target, $field)) {
            return in_array($field[1], [...self::APP_URL_FIELDS, ...MergeContext::SECRET_PATHS], true);
        }

        return ! str_contains($target, '{{') && self::sameHost($target);
    }

    /**
     * @param  list<string>  $extraHosts  hosts allowed besides the application's own
     */
    private static function sameHost(string $url, array $extraHosts = []): bool
    {
        $host = parse_url(str_starts_with($url, '//') ? 'https:'.$url : $url, PHP_URL_HOST);

        if (! is_string($host) || preg_match('#^(https?:)?//#i', $url) !== 1) {
            return false;
        }

        foreach ([self::appHost(), ...$extraHosts] as $allowed) {
            if (strcasecmp(rtrim($host, '.'), rtrim($allowed, '.')) === 0) {
                return true;
            }
        }

        return false;
    }

    private static function appHost(): string
    {
        return (string) parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}
