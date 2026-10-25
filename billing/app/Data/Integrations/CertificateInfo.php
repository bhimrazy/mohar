<?php

namespace App\Data\Integrations;

use Carbon\CarbonImmutable;

/**
 * Public facts about a stored certificate (FieldType::Certificate), kept beside the encrypted PEM key so forms and the
 * expiry check never read the key. Nothing here is secret.
 */
final readonly class CertificateInfo
{
    public function __construct(
        public string $subject,
        public string $issuer,
        public string $serial,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
    ) {}

    /**
     * @param  array{subject?: mixed, issuer?: mixed, serial?: mixed, not_before?: mixed, not_after?: mixed}  $data
     */
    public static function fromArray(array $data): ?self
    {
        if (! is_string($data['not_before'] ?? null) || ! is_string($data['not_after'] ?? null)) {
            return null;
        }

        return new self(
            subject: is_string($data['subject'] ?? null) ? $data['subject'] : '',
            issuer: is_string($data['issuer'] ?? null) ? $data['issuer'] : '',
            serial: is_string($data['serial'] ?? null) ? $data['serial'] : '',
            notBefore: CarbonImmutable::parse($data['not_before'])->utc(),
            notAfter: CarbonImmutable::parse($data['not_after'])->utc(),
        );
    }

    /**
     * @return array{subject: string, issuer: string, serial: string, not_before: string, not_after: string}
     */
    public function toArray(): array
    {
        return [
            'subject' => $this->subject,
            'issuer' => $this->issuer,
            'serial' => $this->serial,
            'not_before' => $this->notBefore->utc()->toIso8601String(),
            'not_after' => $this->notAfter->utc()->toIso8601String(),
        ];
    }
}
