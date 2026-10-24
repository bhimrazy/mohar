<?php

namespace App\Modules\Core\Console\Commands;

use App\Contracts\Branding\BrandProvider;
use App\Models\User;
use App\Modules\Core\Install\AdministratorRefused;
use App\Modules\Core\Install\CheckStatus;
use App\Modules\Core\Install\DefaultCurrencies;
use App\Modules\Core\Install\EnvironmentCheck;
use App\Modules\Core\Install\EnvironmentChecks;
use App\Modules\Core\Install\FirstAdministrator;
use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Modules\Core\Install\RegionalDefaults;
use App\Modules\Core\Models\Currency;
use App\Support\Queues;
use App\Support\Security\PasswordPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\Console\Output\OutputInterface;

use function Laravel\Prompts\password;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;

/**
 * The production install path (docs/devops/install.md): pre-flight checks, reference data from every module's
 * InstallsReferenceData, and the first full administrator. Safe to re-run: reference data is idempotent and a second
 * administrator is created only with --additional-admin. One run at a time (LOCK). It never creates demo data.
 */
#[Signature('app:install
    {--admin-name= : Name of the full administrator to create}
    {--admin-email= : Create the full administrator without prompts, with a generated one-time password printed once}
    {--additional-admin : Create a full administrator even though one already exists}
    {--country= : Two-letter country code for a first install (default NP, Nepal)}
    {--currency= : Default currency for a first install: NPR, USD or INR (default NPR)}
    {--timezone= : Company time zone for a first install (default Asia/Kathmandu)}')]
#[Description('Check the environment, install reference data and create the first full administrator')]
class InstallCommand extends Command
{
    /** Cache lock held for the whole install after the pre-flight, so two runs never write the same rows at once. */
    public const LOCK = 'app:install';

    /** Long enough for the prompts of an interactive install; released as soon as the run ends. */
    private const LOCK_SECONDS = 900;

    public function handle(EnvironmentChecks $environment, ReferenceDataInstaller $installer, FirstAdministrator $administrators, RegionalDefaults $regional): int
    {
        if (! $this->preflight($environment->run())) {
            return self::FAILURE;
        }

        $lock = Cache::lock(self::LOCK, self::LOCK_SECONDS);

        if (! $lock->get()) {
            $this->components->error('Another app:install is running. Wait for it to finish, then run `php artisan app:install` again.');

            return self::FAILURE;
        }

        try {
            return $this->install($installer, $administrators, $regional);
        } catch (UniqueConstraintViolationException) {
            // The lock only serialises runs that share the cache store; a run on another store can still collide.
            $this->components->error('Another install wrote the same rows at the same time, so this one stopped. Run `php artisan app:install` again: it keeps what exists and adds only what is missing.');

            return self::FAILURE;
        } finally {
            $lock->release();
        }
    }

    private function install(ReferenceDataInstaller $installer, FirstAdministrator $administrators, RegionalDefaults $regional): int
    {
        if (! $this->regional($regional)) {
            return self::FAILURE;
        }

        $this->components->info('Installing reference data for '.$this->brandName().'.');
        $this->table(['Reference data', 'Created', 'Updated', 'Unchanged'], array_values(array_map(
            fn (array $entry): array => [$entry['label'], $entry['result']->created, $entry['result']->updated, $entry['result']->unchanged],
            $installer->install(),
        )));
        $this->line('  <fg=gray>Settings, the automation schedule and theme presets ship as built-in defaults, so nothing is stored for them until staff change them.</>');

        $status = $this->administrator($administrators);

        if ($status === self::SUCCESS) {
            $this->nextSteps();
        }

        return $status;
    }

    /**
     * The country, currency and time zone of a first install: Nepal's by default, changed by options or, in a
     * terminal, by prompts. An existing installation (it already has currencies) keeps what it uses.
     */
    private function regional(RegionalDefaults $regional): bool
    {
        $given = [$this->option('country'), $this->option('currency'), $this->option('timezone')];

        if (Currency::query()->exists()) {
            if (array_filter($given) !== []) {
                $this->components->warn('This installation already has currencies, so --country, --currency and --timezone were ignored. Change them in Admin → Settings → Company and Setup → Currencies.');
            }

            return true;
        }

        $country = trim((string) $this->option('country'));
        $currency = trim((string) $this->option('currency'));
        $timezone = trim((string) $this->option('timezone'));

        if ($this->input->isInteractive() && array_filter($given) === []) {
            $this->components->info('Regional defaults (change any of them later in Admin).');
            $country = text('Country (two-letter code)', default: RegionalDefaults::COUNTRY, required: true, validate: fn (string $value): ?string => RegionalDefaults::isCountry(trim($value)) ? null : 'Use a two-letter ISO country code, such as NP.');
            $currency = select('Default currency', array_keys(DefaultCurrencies::CURRENCIES), default: RegionalDefaults::CURRENCY);
            $timezone = text('Company time zone', default: RegionalDefaults::TIMEZONE, required: true, validate: fn (string $value): ?string => RegionalDefaults::isTimezone(trim($value)) ? null : 'Use a time zone name such as Asia/Kathmandu.');
        }

        $country = $country === '' ? RegionalDefaults::COUNTRY : $country;
        $currency = $currency === '' ? RegionalDefaults::CURRENCY : strtoupper((string) $currency);
        $timezone = $timezone === '' ? RegionalDefaults::TIMEZONE : $timezone;

        $error = match (true) {
            ! RegionalDefaults::isCountry($country) => "--country must be a two-letter ISO country code, such as NP; got \"{$country}\".",
            ! array_key_exists($currency, DefaultCurrencies::CURRENCIES) => '--currency must be one of '.implode(', ', array_keys(DefaultCurrencies::CURRENCIES)).". Add other currencies in Setup → Currencies after the install; got \"{$currency}\".",
            ! RegionalDefaults::isTimezone($timezone) => "--timezone must be a time zone name such as Asia/Kathmandu; got \"{$timezone}\".",
            default => null,
        };

        if ($error !== null) {
            $this->components->error($error);

            return false;
        }

        $regional->choose($country, $currency, $timezone);
        $this->line('  <fg=gray>Country '.strtoupper((string) $country).', default currency '.$currency.', time zone '.$timezone.'.</>');

        return true;
    }

    /**
     * @param  list<EnvironmentCheck>  $checks
     */
    private function preflight(array $checks): bool
    {
        $this->components->info('Checking the environment.');

        foreach ($checks as $check) {
            $this->components->twoColumnDetail($check->label, match ($check->status) {
                CheckStatus::Ok => '<fg=green;options=bold>OK</>',
                CheckStatus::Warning => '<fg=yellow;options=bold>WARNING</>',
                CheckStatus::Failed => '<fg=red;options=bold>FAILED</>',
                CheckStatus::Skipped => '<fg=gray>SKIPPED</>',
            });

            if ($check->status !== CheckStatus::Ok) {
                $this->line('  <fg=gray>'.$check->detail.'</>');
            }
        }

        $failed = array_values(array_filter($checks, fn (EnvironmentCheck $check): bool => $check->failed()));

        if ($failed !== []) {
            $this->newLine();
            $this->components->error('Install stopped. Fix the failed checks above and run `php artisan app:install` again: '
                .implode('; ', array_map(fn (EnvironmentCheck $check): string => "{$check->label}: {$check->detail}", $failed)));

            return false;
        }

        return true;
    }

    private function administrator(FirstAdministrator $administrators): int
    {
        $email = trim((string) $this->option('admin-email'));
        $additional = (bool) $this->option('additional-admin');
        $existing = $administrators->count();

        if ($email !== '') {
            return $this->administratorWithGeneratedPassword($administrators, mb_strtolower($email), $additional);
        }

        if ($existing > 0 && ! $additional) {
            $this->components->info("{$existing} full administrator(s) already exist, so none was created. Pass --additional-admin to add another.");

            return self::SUCCESS;
        }

        if (! $this->input->isInteractive()) {
            $this->components->error('No full administrator was created. Run `php artisan app:install` in a terminal to choose a password, or pass --admin-email=<address> for a generated one-time password.');

            return self::FAILURE;
        }

        $this->components->info('Create a full administrator.');

        $name = text(
            label: 'Administrator name',
            default: (string) $this->option('admin-name'),
            required: true,
            validate: ['name' => ['required', 'string', 'max:255']],
        );
        $email = mb_strtolower(trim(text(
            label: 'Administrator email',
            required: true,
            validate: fn (string $value): ?string => $this->emailError(mb_strtolower(trim($value))),
        )));
        $password = password(
            label: 'Password',
            required: true,
            validate: ['password' => FirstAdministrator::passwordRules()],
            hint: 'At least '.PasswordPolicy::MIN_LENGTH.' characters with upper- and lower-case letters, a number and a symbol, and not in a known breach.',
        );
        password(
            label: 'Confirm password',
            required: true,
            validate: fn (string $value): ?string => hash_equals($password, $value) ? null : 'The passwords do not match.',
        );

        try {
            $administrators->create(trim($name), $email, $password, temporary: false, additional: $additional);
        } catch (AdministratorRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Full administrator {$email} created. Two-factor authentication is set up at first sign-in.");

        return self::SUCCESS;
    }

    private function administratorWithGeneratedPassword(FirstAdministrator $administrators, string $email, bool $additional): int
    {
        $existing = User::query()->where('email', $email)->first();

        if ($existing !== null) {
            if ($administrators->isFullAdministrator($existing)) {
                $this->components->info("{$email} is already a full administrator; nothing changed.");

                return self::SUCCESS;
            }

            $this->components->error("{$email} already belongs to an account that is not a full administrator. The installer does not promote existing accounts: use Admin → Staff, or choose another address.");

            return self::FAILURE;
        }

        $name = trim((string) $this->option('admin-name')) ?: 'Administrator';
        $errors = Validator::make(['email' => $email, 'name' => $name], [
            'email' => ['required', 'string', 'email', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
        ])->errors();

        if ($errors->isNotEmpty()) {
            $this->components->error((string) $errors->first());

            return self::FAILURE;
        }

        $password = FirstAdministrator::generatePassword();

        try {
            $administrators->create($name, $email, $password, temporary: true, additional: $additional);
        } catch (AdministratorRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info("Full administrator {$email} created with a one-time password. It is shown once, works for ".FirstAdministrator::ONE_TIME_PASSWORD_HOURS.' hours and must be changed at first sign-in, followed by two-factor setup:');
        // Raw: a generated password can contain "<info>", "</>" or "\<", which the console formatter would rewrite.
        $this->output->writeln('  '.$password, OutputInterface::OUTPUT_RAW);
        $this->newLine();

        return self::SUCCESS;
    }

    private function emailError(string $email): ?string
    {
        $validator = Validator::make(['email' => $email], ['email' => ['required', 'string', 'email', 'max:255']]);

        if ($validator->fails()) {
            return (string) $validator->errors()->first('email');
        }

        return User::query()->where('email', $email)->exists()
            ? 'That address already belongs to an account. The installer does not promote existing accounts: use Admin → Staff, or choose another address.'
            : null;
    }

    private function nextSteps(): void
    {
        $url = rtrim((string) config('app.url'), '/');
        $this->components->info('Next steps (docs/devops/install.md).');
        $this->line('  1. Scheduler: add this cron entry for the web server user:');
        $this->line('       * * * * * cd '.base_path().' && php artisan schedule:run >> /dev/null 2>&1');
        $this->line('  2. Queue workers: keep these running under systemd or Supervisor:');
        foreach (Queues::workerCommands() as $command) {
            $this->line('       '.$command);
        }
        $this->line('  3. Mail: set MAIL_MAILER, MAIL_HOST, MAIL_PORT, MAIL_USERNAME, MAIL_PASSWORD and MAIL_FROM_ADDRESS in .env.');
        $this->line('  4. Object storage: run `php artisan objects:ensure-bucket` once the OBJECTS_* settings point at your bucket.');
        $this->line("  5. Sign in at {$url}/login and set up two-factor authentication.");

        // The settings and currency screens belong to the Platform module, which may not be installed.
        if (Route::has('admin.platform.settings.edit')) {
            $this->line('  6. Company profile: '.route('admin.platform.settings.edit', 'company'));
        }

        if (Route::has('admin.platform.currencies.index')) {
            $this->line('  7. Exchange rates: review the starting rates at '.route('admin.platform.currencies.index'));
        }
    }

    private function brandName(): string
    {
        return $this->laravel->make(BrandProvider::class)->brand()->name;
    }
}
