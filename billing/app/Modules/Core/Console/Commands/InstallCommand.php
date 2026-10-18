<?php

namespace App\Modules\Core\Console\Commands;

use App\Contracts\Branding\BrandProvider;
use App\Models\User;
use App\Modules\Core\Install\CheckStatus;
use App\Modules\Core\Install\EnvironmentCheck;
use App\Modules\Core\Install\EnvironmentChecks;
use App\Modules\Core\Install\FirstAdministrator;
use App\Modules\Core\Install\ReferenceDataInstaller;
use App\Support\Security\PasswordPolicy;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

/**
 * The production install path (docs/devops/install.md): pre-flight checks, reference data from every module's
 * InstallsReferenceData, and the first full administrator. Safe to re-run: reference data is idempotent and a second
 * administrator is created only with --additional-admin. It never creates demo data.
 */
#[Signature('app:install
    {--admin-name= : Name of the full administrator to create}
    {--admin-email= : Create the full administrator without prompts, with a generated one-time password printed once}
    {--additional-admin : Create a full administrator even though one already exists}')]
#[Description('Check the environment, install reference data and create the first full administrator')]
class InstallCommand extends Command
{
    public function handle(EnvironmentChecks $environment, ReferenceDataInstaller $installer, FirstAdministrator $administrators): int
    {
        if (! $this->preflight($environment->run())) {
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
            return $this->administratorWithGeneratedPassword($administrators, mb_strtolower($email), $existing > 0 && ! $additional);
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

        $administrators->create(trim($name), $email, $password, temporary: false);
        $this->components->info("Full administrator {$email} created. Two-factor authentication is set up at first sign-in.");

        return self::SUCCESS;
    }

    private function administratorWithGeneratedPassword(FirstAdministrator $administrators, string $email, bool $refuseNew): int
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

        if ($refuseNew) {
            $this->components->error('A full administrator already exists, so none was created. Pass --additional-admin to add another.');

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
        $administrators->create($name, $email, $password, temporary: true);

        $this->components->info("Full administrator {$email} created with a one-time password. It is shown once and must be changed at first sign-in, followed by two-factor setup:");
        $this->line('  '.$password);
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
        $this->line('       php artisan queue:work --queue=payments,provisioning,registrar --tries=3');
        $this->line('       php artisan queue:work --queue=notifications,webhooks --tries=3');
        $this->line('       php artisan queue:work --queue=default --tries=3');
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
