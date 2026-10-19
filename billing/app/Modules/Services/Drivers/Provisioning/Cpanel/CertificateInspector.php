<?php

namespace App\Modules\Services\Drivers\Provisioning\Cpanel;

use App\Data\Integrations\ResolvedAddress;

/**
 * Reads the certificate a server presents, without trusting it, so staff can compare its fingerprint before they pin
 * it ("Trust this certificate", spec §9.3). Connects to the address the outbound guard checked, never to a fresh DNS
 * answer. Nothing is sent after the handshake.
 */
class CertificateInspector
{
    /**
     * @return array{subject: string, issuer: string, expires_at: string, spki_sha256: string}|null null when no
     *                                                                                              certificate could be read
     */
    public function inspect(ResolvedAddress $address, string $serverName, int $timeoutSeconds = 10): ?array
    {
        $context = stream_context_create(['ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
            'capture_peer_cert' => true,
            'SNI_enabled' => filter_var($serverName, FILTER_VALIDATE_IP) === false,
            'peer_name' => $serverName,
        ]]);

        $ip = str_contains($address->ip, ':') ? "[{$address->ip}]" : $address->ip;
        $socket = @stream_socket_client("ssl://{$ip}:{$address->port}", $errorCode, $errorMessage, $timeoutSeconds, STREAM_CLIENT_CONNECT, $context);

        if ($socket === false) {
            return null;
        }

        $certificate = stream_context_get_params($socket)['options']['ssl']['peer_certificate'] ?? null;
        fclose($socket);

        return $certificate instanceof \OpenSSLCertificate ? self::describe($certificate) : null;
    }

    /**
     * @return array{subject: string, issuer: string, expires_at: string, spki_sha256: string}|null
     */
    public static function describe(\OpenSSLCertificate|string $certificate): ?array
    {
        $parsed = openssl_x509_parse($certificate);
        $key = openssl_pkey_get_public($certificate);
        $pem = $key === false ? null : (openssl_pkey_get_details($key)['key'] ?? null);

        if ($parsed === false || ! is_string($pem)) {
            return null;
        }

        return [
            'subject' => self::name($parsed['subject'] ?? []),
            'issuer' => self::name($parsed['issuer'] ?? []),
            'expires_at' => gmdate('Y-m-d\TH:i:s\Z', (int) ($parsed['validTo_time_t'] ?? 0)),
            'spki_sha256' => self::spkiPin($pem),
        ];
    }

    /**
     * The SHA-256 of the DER SubjectPublicKeyInfo, base64-encoded: the form curl's pinned public key option takes.
     */
    public static function spkiPin(string $publicKeyPem): string
    {
        $der = base64_decode((string) preg_replace('/-----[^-]+-----|\s+/', '', $publicKeyPem), true);

        return base64_encode(hash('sha256', (string) $der, true));
    }

    /**
     * @param  array<string, string|list<string>>  $name
     */
    private static function name(array $name): string
    {
        $parts = [];

        foreach (['CN', 'O', 'OU'] as $field) {
            if (isset($name[$field])) {
                $parts[] = $field.'='.implode(', ', (array) $name[$field]);
            }
        }

        return implode(', ', $parts);
    }
}
