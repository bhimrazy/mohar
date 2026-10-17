<?php

namespace App\Modules\Support\Drivers\Inbound;

use Illuminate\Http\Request;

/**
 * Generic JSON webhook, signed with HMAC-SHA256 of the raw body in the X-Support-Signature header
 * ("sha256=<hex>"). Payload:
 *
 *   {"message_id": "<id@host>", "from": "Jane <jane@example.com>", "to": ["support@example.com"], "cc": [],
 *    "subject": "...", "text": "...", "headers": {"Auto-Submitted": "no"}, "in_reply_to": "<...>",
 *    "references": ["<...>"], "date": "RFC 2822", "attachments": [{"filename": "a.pdf", "content": "<base64>"}]}
 */
class GenericDriver implements InboundDriver
{
    public const SIGNATURE_HEADER = 'X-Support-Signature';

    public function verify(Request $request, string $secret): bool
    {
        if ($secret === '') {
            return false;
        }

        $signature = (string) $request->header(self::SIGNATURE_HEADER, '');
        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    public static function sign(string $body, string $secret): string
    {
        return 'sha256='.hash_hmac('sha256', $body, $secret);
    }

    public function parse(array $payload): InboundMessage
    {
        [$fromEmail, $fromName] = $this->address($payload['from'] ?? '');
        $headers = [];

        foreach ((array) ($payload['headers'] ?? []) as $name => $value) {
            $headers[strtolower((string) $name)] = is_scalar($value) ? (string) $value : '';
        }

        $attachments = [];

        foreach ((array) ($payload['attachments'] ?? []) as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $content = base64_decode((string) ($attachment['content'] ?? ''), true);

            if ($content !== false) {
                $attachments[] = ['name' => (string) ($attachment['filename'] ?? $attachment['name'] ?? ''), 'content' => $content];
            }
        }

        return new InboundMessage(
            provider: 'generic',
            messageId: isset($payload['message_id']) ? (string) $payload['message_id'] : ($headers['message-id'] ?? null),
            fromEmail: $fromEmail,
            fromName: $fromName,
            to: $this->addresses($payload['to'] ?? []),
            cc: $this->addresses($payload['cc'] ?? []),
            deliveredTo: isset($payload['delivered_to']) ? strtolower((string) $payload['delivered_to']) : ($headers['delivered-to'] ?? null),
            subject: mb_substr(trim((string) preg_replace('/\p{Cc}+/u', ' ', (string) ($payload['subject'] ?? ''))), 0, 255),
            text: (string) ($payload['text'] ?? ''),
            headers: $headers,
            inReplyTo: isset($payload['in_reply_to']) ? (string) $payload['in_reply_to'] : ($headers['in-reply-to'] ?? null),
            references: array_values(array_filter(array_map(
                fn (mixed $reference): string => is_scalar($reference) ? trim((string) $reference) : '',
                is_array($payload['references'] ?? null) ? $payload['references'] : (preg_split('/\s+/', (string) ($payload['references'] ?? $headers['references'] ?? '')) ?: []),
            ))),
            date: isset($payload['date']) ? (string) $payload['date'] : ($headers['date'] ?? null),
            attachments: $attachments,
            authenticated: $this->authenticationVerdict($payload, $headers),
        );
    }

    /**
     * The provider's sender-authentication verdict, from an "auth" object ({"spf": "pass", "dkim": "pass",
     * "dmarc": "pass"}) or an Authentication-Results header. DMARC pass, or SPF and DKIM pass, counts as
     * authenticated; any explicit fail without such a pass counts as failed; nothing known is null.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function authenticationVerdict(array $payload, array $headers): ?bool
    {
        $results = [];

        if (is_array($payload['auth'] ?? null)) {
            foreach (['spf', 'dkim', 'dmarc'] as $method) {
                if (isset($payload['auth'][$method]) && is_scalar($payload['auth'][$method])) {
                    $results[$method] = strtolower((string) $payload['auth'][$method]);
                }
            }
        } elseif (isset($headers['authentication-results'])) {
            preg_match_all('/\b(spf|dkim|dmarc)=([a-z]+)/i', $headers['authentication-results'], $matches, PREG_SET_ORDER);

            foreach ($matches as $match) {
                $results[strtolower($match[1])] ??= strtolower($match[2]);
            }
        }

        if ($results === []) {
            return null;
        }

        if (($results['dmarc'] ?? null) === 'pass' || (($results['spf'] ?? null) === 'pass' && ($results['dkim'] ?? null) === 'pass')) {
            return true;
        }

        return in_array('fail', $results, true) || in_array('softfail', $results, true) ? false : null;
    }

    /**
     * @return array{0: string, 1: string} [email, name]
     */
    private function address(mixed $value): array
    {
        if (is_array($value)) {
            return [strtolower(trim((string) ($value['email'] ?? ''))), trim((string) ($value['name'] ?? ''))];
        }

        $value = trim(is_scalar($value) ? (string) $value : '');

        if (preg_match('/^(.*)<([^>]+)>$/', $value, $match) === 1) {
            return [mb_substr(strtolower(trim($match[2])), 0, 255), mb_substr(trim($match[1], " \"'"), 0, 255)];
        }

        return [mb_substr(strtolower($value), 0, 255), ''];
    }

    /**
     * @return list<string>
     */
    private function addresses(mixed $value): array
    {
        $values = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');

        return array_values(array_filter(array_map(fn (mixed $item): string => $this->address($item)[0], $values)));
    }
}
