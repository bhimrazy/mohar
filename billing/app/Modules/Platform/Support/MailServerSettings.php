<?php

namespace App\Modules\Platform\Support;

use App\Modules\Core\Support\Settings;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\MailManager;

/**
 * Admin → Settings → Mail server. A value saved there replaces its .env counterpart in the mail config; a value never
 * saved keeps the .env one. Applied when the mail manager is built in a process and again before every queued job:
 * settings are re-read per job, so a running queue worker picks up a change without `queue:restart`. When the
 * effective values change, the mailers already built are dropped and rebuilt on the next send.
 */
final class MailServerSettings
{
    public const SECTION = 'mail';

    /** Mailer a test send uses: the default mailer's config with a short timeout. */
    public const TEST_MAILER = 'settings_test';

    /** Seconds a test send waits for the mail server before giving up. */
    public const TEST_TIMEOUT = 15;

    /** The transports this release can build without extra packages (SES uses the AWS SDK already installed). */
    public const MAILERS = [
        'smtp' => 'SMTP server',
        'ses' => 'Amazon SES',
        'log' => 'Log only (nothing is sent)',
    ];

    public const ENCRYPTION = [
        'starttls' => 'STARTTLS, required (usually port 587)',
        'tls' => 'TLS from the start (usually port 465)',
        'auto' => 'Automatic: TLS on port 465, otherwise STARTTLS when the server offers it',
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
     * Registers the test mailer: the default mailer as it is now configured, with a timeout so a silent server cannot
     * hold the request. Null when the default mailer is not defined.
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
            'smtp' => [...$config, 'timeout' => self::TEST_TIMEOUT],
            'ses', 'ses-v2' => [...$config, 'http' => ['timeout' => self::TEST_TIMEOUT, 'connect_timeout' => self::TEST_TIMEOUT]],
            default => $config,
        };
        config(['mail.mailers.'.self::TEST_MAILER => $config]);
        $this->app->make('mail.manager')->purge(self::TEST_MAILER);

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

        if (array_key_exists('mail.mailers.smtp.encryption', $overrides)) {
            // "auto" leaves the scheme to the framework (smtps on port 465), which then uses STARTTLS when offered.
            [$scheme, $requireTls] = match ($overrides['mail.mailers.smtp.encryption']) {
                'tls' => ['smtps', false],
                'starttls' => ['smtp', true],
                default => [null, false],
            };
            $overrides['mail.mailers.smtp.scheme'] = $scheme;
            $overrides['mail.mailers.smtp.require_tls'] = $requireTls;
        }

        return $overrides;
    }
}
