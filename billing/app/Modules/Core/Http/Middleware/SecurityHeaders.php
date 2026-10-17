<?php

namespace App\Modules\Core\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

/**
 * Baseline browser security headers for every web response (docs/security/STANDARDS.md §1).
 *
 * The Content-Security-Policy allows scripts only from this origin plus a per-request nonce that Vite
 * stamps on its tags (Vite::useCspNonce()); inline scripts in Blade must carry nonce="{{ Vite::cspNonce() }}".
 * While the Vite dev server runs (public/hot), its origin is allowed for scripts, styles and HMR sockets.
 * Responses that already set a header (e.g. Referrer-Policy: no-referrer on token pages) keep theirs.
 */
class SecurityHeaders
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $headers = [
            'Content-Security-Policy' => $this->contentSecurityPolicy($nonce),
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=(), payment=(), usb=(), interest-cohort=()',
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        if ($request->isSecure()) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000; includeSubDomains';
        }

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        $dev = $this->viteDevServer();
        $devSocket = $dev === null ? '' : ' '.preg_replace('#^http#', 'ws', $dev);
        $devOrigin = $dev === null ? '' : " {$dev}";

        return implode('; ', [
            "default-src 'self'",
            "script-src 'self' 'nonce-{$nonce}'{$devOrigin}",
            // Inline style attributes come from Radix/React (positioning, CSS variables).
            "style-src 'self' 'unsafe-inline'{$devOrigin}",
            "img-src 'self' data: blob:",
            "font-src 'self' data:{$devOrigin}",
            "connect-src 'self'{$devOrigin}{$devSocket}",
            "object-src 'none'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'none'",
        ]);
    }

    private function viteDevServer(): ?string
    {
        $hot = Vite::hotFile();

        if (! is_file($hot)) {
            return null;
        }

        $url = trim((string) file_get_contents($hot));
        $parts = parse_url($url);

        return isset($parts['scheme'], $parts['host'])
            ? $parts['scheme'].'://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '')
            : null;
    }
}
