<?php

namespace App\Console\Commands;

use App\Services\DigestSender;
use Illuminate\Console\Command;

/**
 * The weekly digest run. Scheduled in routes/console.php; safe to run by
 * hand, because DigestSender refuses to write to anyone twice inside the
 * window whatever invokes it.
 */
class SendDigests extends Command
{
    protected $signature = 'riff:send-digests {--limit= : How many accounts to write to in this run}';

    protected $description = 'Send the weekly "your scene" digest to everyone due for one';

    public function handle(DigestSender $sender): int
    {
        $limit = $this->option('limit');

        $sent = $sender->run($limit === null ? null : (int) $limit);

        $this->info($sent === 0
            ? 'Nobody was due a digest with anything in it.'
            : "Queued {$sent} ".($sent === 1 ? 'digest' : 'digests').'.');

        return self::SUCCESS;
    }
}
