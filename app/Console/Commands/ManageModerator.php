<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The only way to grant moderator access: no form, no self-service, and the
 * column is not fillable, so no request can set it.
 */
class ManageModerator extends Command
{
    protected $signature = 'riff:moderator {email : The account to change} {--revoke : Take moderator access away instead}';

    protected $description = 'Grant or revoke moderator access for an account';

    public function handle(): int
    {
        $user = User::firstWhere('email', $this->argument('email'));

        if ($user === null) {
            $this->error("No account with the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $grant = ! $this->option('revoke');

        if ($user->is_moderator === $grant) {
            $this->info($grant
                ? "{$user->name} is already a moderator."
                : "{$user->name} is not a moderator.");

            return self::SUCCESS;
        }

        $user->forceFill(['is_moderator' => $grant])->save();

        $this->info($grant
            ? "{$user->name} ({$user->email}) can now work the report queue."
            : "Moderator access removed from {$user->name} ({$user->email}).");

        return self::SUCCESS;
    }
}
