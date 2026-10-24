<?php

namespace App\Modules\Core\Console\Commands;

use App\Models\User;
use App\Modules\Core\Install\AdministratorRefused;
use App\Modules\Core\Install\FirstAdministrator;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The server-side recovery for a full administrator (#60): a new one-time password, printed once, that must be
 * changed at sign-in and expires like the installer's. The old password's sessions and remember-me cookies end and
 * the administrator is mailed; two-factor setup and passkeys are left as they are.
 */
#[Signature('app:admin-password {email : Email address of an active full administrator}')]
#[Description('Give a full administrator a new one-time password, printed once')]
class AdminPasswordCommand extends Command
{
    public function handle(FirstAdministrator $administrators): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->components->error("No account uses {$email}.");

            return self::FAILURE;
        }

        try {
            $password = $administrators->reissue($user, 'console: '.get_current_user().'@'.(gethostname() ?: 'unknown host'));
        } catch (AdministratorRefused $refused) {
            $this->components->error($refused->getMessage());

            return self::FAILURE;
        }

        $this->components->info("New one-time password for {$email}. It is shown once, works for ".FirstAdministrator::ONE_TIME_PASSWORD_HOURS.' hours and must be changed at sign-in:');
        // Raw: a generated password can contain console markup characters (see InstallCommand).
        $this->output->writeln('  '.$password, OutputInterface::OUTPUT_RAW);
        $this->newLine();

        return self::SUCCESS;
    }
}
