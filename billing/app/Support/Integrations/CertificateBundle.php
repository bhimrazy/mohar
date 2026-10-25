<?php

namespace App\Support\Integrations;

use App\Data\Integrations\CertificateInfo;
use App\Modules\Core\Support\DateFormats;
use Carbon\CarbonImmutable;
use OpenSSLAsymmetricKey;

/**
 * A private key and its certificate read from a PKCS#12 file (.pfx/.p12) in memory, for a FieldType::Certificate
 * setting. The file and its passphrase are never kept: the host stores the unencrypted PEM key in its encrypted
 * settings, and the metadata beside it. Only OpenSSL parses the upload.
 *
 * @api
 */
final readonly class CertificateBundle
{
    /** Largest upload read, in bytes. A signing certificate with its chain is a few kilobytes. */
    public const MAX_BYTES = 65_536;

    public function __construct(
        #[\SensitiveParameter] public string $privateKeyPem,
        public CertificateInfo $info,
    ) {}

    /**
     * @throws CertificateRefused with a message staff can act on; it never contains the passphrase or the key
     */
    public static function fromPkcs12(#[\SensitiveParameter] string $contents, #[\SensitiveParameter] string $passphrase): self
    {
        if ($contents === '' || strlen($contents) > self::MAX_BYTES) {
            throw new CertificateRefused(__('Upload a .pfx or .p12 file of at most :size KB.', ['size' => self::MAX_BYTES / 1024]));
        }

        self::clearErrors();
        $parts = [];

        if (! openssl_pkcs12_read($contents, $parts, $passphrase)) {
            // OpenSSL 3 reads RC2 and 3DES-protected files (older exports) only with its legacy provider.
            throw new CertificateRefused(str_contains(self::clearErrors(), 'unsupported')
                ? __('This file uses old encryption that this server cannot read. Ask the provider for a new file, or export it again with AES encryption.')
                : __('This file could not be opened with that passphrase. Check the passphrase, and that the file is the .pfx or .p12 file the provider sent.'));
        }

        $key = is_string($parts['pkey'] ?? null) ? openssl_pkey_get_private($parts['pkey']) : false;
        $certificate = is_string($parts['cert'] ?? null) ? $parts['cert'] : '';

        if (! $key instanceof OpenSSLAsymmetricKey) {
            throw new CertificateRefused(__('This file has no private key. Upload the .pfx or .p12 file that includes the key, not the certificate alone.'));
        }

        $parsed = $certificate !== '' ? openssl_x509_parse($certificate) : false;

        if (! is_array($parsed) || ! openssl_x509_check_private_key($certificate, $key)) {
            self::clearErrors();

            throw new CertificateRefused(__('The private key in this file does not belong to its certificate. Ask the provider for the correct file.'));
        }

        $pem = '';
        $exported = openssl_pkey_export($key, $pem);
        self::clearErrors();

        if (! $exported || $pem === '') {
            throw new CertificateRefused(__('The private key in this file could not be read. Ask the provider for a new file.'));
        }

        $info = new CertificateInfo(
            subject: self::name($parsed['subject'] ?? []),
            issuer: self::name($parsed['issuer'] ?? []),
            serial: is_string($parsed['serialNumberHex'] ?? null) ? $parsed['serialNumberHex'] : '',
            notBefore: CarbonImmutable::createFromTimestampUTC((int) ($parsed['validFrom_time_t'] ?? 0)),
            notAfter: CarbonImmutable::createFromTimestampUTC((int) ($parsed['validTo_time_t'] ?? 0)),
        );

        if ($info->notAfter->isPast()) {
            throw new CertificateRefused(__('This certificate expired on :date. Ask the provider for a new one.', ['date' => $info->notAfter->format(DateFormats::current())]));
        }

        return new self($pem, $info);
    }

    /**
     * "CN=…, O=…, C=…" from openssl_x509_parse()'s subject or issuer.
     */
    private static function name(mixed $parts): string
    {
        if (! is_array($parts)) {
            return '';
        }

        $pairs = [];

        foreach (array_reverse($parts, true) as $attribute => $value) {
            foreach ((array) $value as $one) {
                $pairs[] = $attribute.'='.(is_scalar($one) ? (string) $one : '');
            }
        }

        return mb_substr(implode(', ', $pairs), 0, 500);
    }

    /** Empties OpenSSL's error queue (it outlives the call) and returns what was in it. */
    private static function clearErrors(): string
    {
        $errors = '';

        while (($error = openssl_error_string()) !== false) {
            $errors .= $error."\n";
        }

        return $errors;
    }
}
