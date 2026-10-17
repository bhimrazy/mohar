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
            subject: mb_substr(trim((string) ($payload['subject'] ?? '')), 0, 255),
            text: (string) ($payload['text'] ?? ''),
            headers: $headers,
            inReplyTo: isset($payload['in_reply_to']) ? (string) $payload['in_reply_to'] : ($headers['in-reply-to'] ?? null),
            references: array_values(array_filter(array_map(
                fn (mixed $reference): string => is_scalar($reference) ? trim((string) $reference) : '',
                is_array($payload['references'] ?? null) ? $payload['references'] : (preg_split('/\s+/', (string) ($payload['references'] ?? $headers['references'] ?? '')) ?: []),
            ))),
            date: isset($payload['date']) ? (string) $payload['date'] : ($headers['date'] ?? null),
            attachments: $attachments,
        );
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
            return [strtolower(trim($match[2])), trim($match[1], " \"'")];
        }

        return [strtolower($value), ''];
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
