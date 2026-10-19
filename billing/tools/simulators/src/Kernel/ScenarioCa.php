<?php

namespace Mohar\Simulators\Kernel;

use RuntimeException;

/**
 * A certificate authority per scenario and the leaf certificate the TLS front presents (SAN IP:127.0.0.1 plus a DNS
 * name). The app trusts the CA through INTEGRATION_CA_BUNDLE, so verification stays on; rotating the leaf gives the
 * server a new key, as a pinned driver must notice.
 */
final class ScenarioCa
{
    public function __construct(private readonly string $directory, private readonly string $dnsName = 'localhost') {}

    public function bundlePath(): string
    {
        return $this->directory.'/ca.pem';
    }

    /** Certificate and key of the leaf, in one file (the TLS front's `local_cert`). */
    public function leafPath(): string
    {
        return $this->directory.'/leaf.pem';
    }

    public function create(): void
    {
        $config = $this->config();
        $caKey = $this->newKey();
        $caCsr = openssl_csr_new(['commonName' => 'Mohar simulator CA'], $caKey, ['config' => $config, 'digest_alg' => 'sha256']);

        if ($caCsr === false || $caCsr === true) {
            throw new RuntimeException('Could not create the scenario CA request.');
        }

        $caCert = openssl_csr_sign($caCsr, null, $caKey, 2, ['config' => $config, 'x509_extensions' => 'v3_ca', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));

        if ($caCert === false) {
            throw new RuntimeException('Could not sign the scenario CA.');
        }

        openssl_x509_export_to_file($caCert, $this->bundlePath());
        openssl_pkey_export_to_file($caKey, $this->directory.'/ca.key');
        $this->rotateLeaf();
    }

    /** Issue a new leaf with a new key; the TLS front presents it from the next connection on. */
    public function rotateLeaf(): void
    {
        $config = $this->config();
        $caCert = (string) file_get_contents($this->bundlePath());
        $caKey = openssl_pkey_get_private((string) file_get_contents($this->directory.'/ca.key'));
        $key = $this->newKey();
        $csr = openssl_csr_new(['commonName' => $this->dnsName], $key, ['config' => $config, 'digest_alg' => 'sha256']);

        if ($caKey === false || $csr === false || $csr === true) {
            throw new RuntimeException('Could not create the scenario leaf request.');
        }

        $leaf = openssl_csr_sign($csr, $caCert, $caKey, 2, ['config' => $config, 'x509_extensions' => 'v3_leaf', 'digest_alg' => 'sha256'], random_int(1, PHP_INT_MAX));

        if ($leaf === false) {
            throw new RuntimeException('Could not sign the scenario leaf.');
        }

        openssl_x509_export($leaf, $certificate);
        openssl_pkey_export($key, $privateKey);
        $temporary = $this->leafPath().'.'.getmypid();
        file_put_contents($temporary, $certificate.$privateKey);
        rename($temporary, $this->leafPath());
    }

    private function newKey(): \OpenSSLAsymmetricKey
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);

        return $key ?: throw new RuntimeException('Could not create a key.');
    }

    private function config(): string
    {
        $path = $this->directory.'/openssl.cnf';

        if (! is_file($path)) {
            file_put_contents($path, implode("\n", [
                '[req]', 'distinguished_name = dn', '[dn]',
                '[v3_ca]', 'basicConstraints = critical, CA:TRUE', 'keyUsage = critical, keyCertSign, cRLSign', 'subjectKeyIdentifier = hash',
                '[v3_leaf]', 'basicConstraints = critical, CA:FALSE', 'keyUsage = critical, digitalSignature', 'extendedKeyUsage = serverAuth',
                "subjectAltName = IP:127.0.0.1, DNS:{$this->dnsName}", '',
            ]));
        }

        return $path;
    }
}
