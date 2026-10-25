<?php

namespace App\Data\Integrations;

use Carbon\CarbonImmutable;

/**
 * Public facts about a stored certificate (FieldType::Certificate): its details, the certificate itself and the chain
 * the uploaded file carried (for mutual TLS later), kept beside the encrypted PEM key so nothing has to read the key
 * or ask for the file again. Nothing here is secret.
 *
 * A host keeps these under SETTINGS_KEY in the integration's settings, keyed by the setting's key; setting keys that
 * start with "_" are reserved for such entries (SettingField).
 */
final readonly class CertificateInfo
{
    public const SETTINGS_KEY = '_certificates';

    /**
     * @param  list<string>  $chainPems  intermediate certificates, PEM
     */
    public function __construct(
        public string $subject,
        public string $issuer,
        public string $serial,
        public CarbonImmutable $notBefore,
        public CarbonImmutable $notAfter,
        public string $certificatePem = '',
        public array $chainPems = [],
    ) {}

    /**
     * @param  array<string, mixed>  $data
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
            certificatePem: is_string($data['certificate'] ?? null) ? $data['certificate'] : '',
            chainPems: array_values(array_filter(is_array($data['chain'] ?? null) ? $data['chain'] : [], 'is_string')),
        );
    }

    /**
     * @return array{subject: string, issuer: string, serial: string, not_before: string, not_after: string, certificate: string, chain: list<string>}
     */
    public function toArray(): array
    {
        return [...$this->summary(), 'certificate' => $this->certificatePem, 'chain' => $this->chainPems];
    }

    /**
     * The details staff see, without the certificate text.
     *
     * @return array{subject: string, issuer: string, serial: string, not_before: string, not_after: string}
     */
    public function summary(): array
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
