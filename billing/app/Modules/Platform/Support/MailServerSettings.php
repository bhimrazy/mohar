<?php

namespace App\Modules\Platform\Support;

use App\Modules\Core\Support\Settings;
use App\Support\Integrations\OutboundAddressGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;
use Illuminate\Support\ConfigurationUrlParser;
use Symfony\Component\Mailer\Transport\Smtp\Stream\SocketStream;

/**
 * Admin → Settings → Mail server. A value saved there replaces its .env counterpart in the mail config; a value never
 * saved keeps the .env one. Applied when the mail manager is built in a process and again before every queued job:
 * settings are re-read per job, so a running queue worker picks up a change without `queue:restart`. When the
 * effective values change, the mailers already built are dropped and rebuilt on the next send. A saved SMTP value also
 * replaces MAIL_URL, which would otherwise override the saved host, port and credentials.
 */
final class MailServerSettings
{
    public const SECTION = 'mail';

    /** Mailer a test send uses: the default mailer's config with a short timeout. */
    public const TEST_MAILER = 'settings_test';

    /** Mail driver of the test mailer's SMTP transport (DeadlineSmtpTransport). */
    private const TEST_DRIVER = 'settings_test_smtp';

    /** Seconds a test send waits for one answer from the mail server. */
    public const TEST_TIMEOUT = 10;

    /** Seconds a whole test send may take before the next SMTP command is refused. */
    public const TEST_DEADLINE = 20;

    /** Settings only staff who can change the mail server see in full; others get them masked. */
    public const CONFIDENTIAL = ['mail.mailers.smtp.username'];

    /** The transports this release can build without extra packages (SES uses the AWS SDK already installed). */
    public const MAILERS = [
        'smtp' => 'SMTP server',
        'ses' => 'Amazon SES',
        'log' => 'Log only (nothing is sent)',
    ];

    public const ENCRYPTION = [
        'starttls' => 'STARTTLS, required (port 587)',
        'tls' => 'TLS (port 465)',
        'auto' => 'Automatic',
    ];

    /** Setting keys. Each is also the config key it overrides, except the encryption, mapped in overrides(). */
    public const KEYS = [
        'mail.default',
        'mail.mailers.smtp.host',
        'mail.mailers.smtp.port',
        'mail.mailers.smtp.encryption',
        'mail.mailers.smtp.username',
        'mail.mailers.smtp.password',
        'services.ses.key',
        'services.ses.secret',
        'services.ses.region',
    ];

    /** @var array<string, mixed>|null the config these settings touch, as loaded from .env */
    private ?array $baseline = null;

    private ?string $applied = null;

    public function __construct(private Application $app, private Settings $settings) {}

    /**
     * What a MAIL_URL in .env does: null when none is set, "overridden" when saved SMTP values replace it, "in effect"
     * when it still applies because no SMTP value is saved here.
     */
    public function envUrl(): ?string
    {
        $this->apply();
        $url = $this->baseline['mail.mailers.smtp']['url'] ?? null;

        if (! is_string($url) || $url === '') {
            return null;
        }

        return $this->smtpSaved() ? 'overridden' : 'in effect';
    }

    /**
     * Why the default mailer's SMTP host may not be used as a mail relay (ADR 0015 relay profile), or null.
     */
    public function relayRefusal(): ?string
    {
        $this->apply();
        $default = (string) config('mail.default');

        if (config("mail.mailers.{$default}.transport") !== 'smtp') {
            return null;
        }

        $config = $this->smtpConfig((array) config("mail.mailers.{$default}"));

        return MailRelayHost::refusal($this->app->make(OutboundAddressGuard::class), (string) ($config['host'] ?? ''));
    }

    /**
     * The fields with confidential values masked, for staff who may view but not change the mail server.
     *
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    public function concealed(array $fields): array
    {
        return array_map(fn (array $field): array => in_array($field['key'], self::CONFIDENTIAL, true) && ($field['is_set'] ?? false)
            ? [...$field, 'value' => '••••••']
            : $field, $fields);
    }

    public function apply(?MailManager $manager = null): void
    {
        $overrides = $this->overrides();
        // Held in memory only; it never leaves the process.
        $fingerprint = hash('sha256', serialize($overrides));

        if ($fingerprint === $this->applied) {
            return;
        }

        $this->baseline ??= [
            'mail.default' => config('mail.default'),
            'mail.mailers.smtp' => config('mail.mailers.smtp'),
            'services.ses' => config('services.ses'),
        ];
        config($this->baseline);
        config($overrides);
        $this->applied = $fingerprint;

        $manager ??= $this->app->resolved('mail.manager') ? $this->app->make('mail.manager') : null;
        $manager?->forgetMailers();
    }

    /** Whether any mail server value is saved in the admin area (otherwise .env alone is in effect). */
    public function savedHere(): bool
    {
        return array_intersect(self::KEYS, array_keys($this->settings->all())) !== [];
    }

    /**
     * Registers the test mailer: the default mailer as it is now configured, with timeouts (and, for SMTP, an overall
     * deadline) so a silent or slow server cannot hold the request. Null when the default mailer is not defined.
     */
    public function registerTestMailer(): ?string
    {
        $this->apply();
        $default = (string) config('mail.default');
        $config = config("mail.mailers.{$default}");

        if (! is_array($config)) {
            return null;
        }

        $config = match ($config['transport'] ?? $default) {
            'smtp' => [...$this->smtpConfig($config), 'transport' => self::TEST_DRIVER, 'timeout' => self::TEST_TIMEOUT],
            'ses', 'ses-v2' => [...$config, 'http' => ['timeout' => self::TEST_TIMEOUT, 'connect_timeout' => self::TEST_TIMEOUT]],
            default => $config,
        };
        config(['mail.mailers.'.self::TEST_MAILER => $config]);
        $manager = $this->app->make('mail.manager');
        $manager->extend(self::TEST_DRIVER, fn (array $config): DeadlineSmtpTransport => $this->deadlineTransport($config));
        $manager->purge(self::TEST_MAILER);

        return $default;
    }

    /**
     * Credentials in effect, for masking a mail server's error text.
     *
     * @return list<string>
     */
    public function secrets(): array
    {
        $values = [config('mail.mailers.smtp.password'), config('services.ses.secret'), config('services.ses.key')];

        return array_values(array_filter($values, fn (mixed $value): bool => is_string($value) && $value !== ''));
    }

    /**
     * Config values from the settings saved in the admin area, by config key.
     *
     * @return array<string, mixed>
     */
    private function overrides(): array
    {
        $stored = $this->settings->all();
        $overrides = [];

        foreach (self::KEYS as $key) {
            if (array_key_exists($key, $stored)) {
                $overrides[$key] = $this->settings->get($key);
            }
        }

        if (isset($overrides['mail.mailers.smtp.port'])) {
            $overrides['mail.mailers.smtp.port'] = (int) $overrides['mail.mailers.smtp.port'];
        }

        // "auto" overrides nothing: the scheme from .env (MAIL_SCHEME) or the framework's own choice (smtps on port 465,
        // else STARTTLS when offered) stays in effect.
        $tls = match ($overrides['mail.mailers.smtp.encryption'] ?? null) {
            'tls' => ['smtps', false],
            'starttls' => ['smtp', true],
            default => null,
        };

        if ($tls !== null) {
            [$overrides['mail.mailers.smtp.scheme'], $overrides['mail.mailers.smtp.require_tls']] = $tls;
        }

        if ($this->smtpSaved()) {
            $overrides['mail.mailers.smtp.url'] = null;
        }

        return $overrides;
    }

    private function smtpSaved(): bool
    {
        return array_filter(array_keys($this->settings->all()), fn (string $key): bool => str_starts_with($key, 'mail.mailers.smtp.')) !== [];
    }

    /**
     * The mailer's config as Laravel resolves it, with a MAIL_URL merged in.
     *
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function smtpConfig(array $config): array
    {
        if (isset($config['url']) && $config['url'] !== '') {
            $config = array_merge($config, (new ConfigurationUrlParser)->parseConfiguration($config));
            unset($config['driver']);
        }

        unset($config['url']);

        return $config;
    }

    /**
     * The SMTP transport of the test mailer: the options the framework would apply, on a transport with a deadline.
     *
     * @param  array<string, mixed>  $config
     */
    private function deadlineTransport(array $config): DeadlineSmtpTransport
    {
        $port = (int) ($config['port'] ?? 0);
        $scheme = ($config['scheme'] ?? null) ?: ($port === 465 ? 'smtps' : 'smtp');
        $transport = new DeadlineSmtpTransport((string) $config['host'], $port, $scheme === 'smtps' ? true : null, (float) self::TEST_DEADLINE);
        $transport->setRequireTls((bool) ($config['require_tls'] ?? false));

        if (($config['username'] ?? '') !== '') {
            $transport->setUsername((string) $config['username']);
        }

        if (($config['password'] ?? '') !== '') {
            $transport->setPassword((string) $config['password']);
        }

        if (($config['local_domain'] ?? '') !== '') {
            $transport->setLocalDomain((string) $config['local_domain']);
        }

        $stream = $transport->getStream();

        if ($stream instanceof SocketStream) {
            $stream->setTimeout((float) ($config['timeout'] ?? self::TEST_TIMEOUT));

            if (($config['source_ip'] ?? '') !== '') {
                $stream->setSourceIp((string) $config['source_ip']);
            }
        }

        return $transport;
    }
}
