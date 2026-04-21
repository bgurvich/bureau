<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Support\ContactMerge;
use Illuminate\Console\Command;

/**
 * Merge two Contact rows from the CLI — first-pass interface for the
 * dedupe feature while the UI is still being designed. Useful right now
 * for cleaning up vendors that slipped in as duplicates before the
 * fingerprint-based dedupe landed.
 */
class MergeContacts extends Command
{
    protected $signature = 'contacts:merge {winner : Contact id to keep} {loser : Contact id to merge in and delete}';

    protected $description = 'Merge the loser Contact into the winner, repointing every FK.';

    public function handle(): int
    {
        $winner = Contact::find((int) $this->argument('winner'));
        $loser = Contact::find((int) $this->argument('loser'));
        if (! $winner) {
            $this->error("Winner contact id {$this->argument('winner')} not found.");

            return self::FAILURE;
        }
        if (! $loser) {
            $this->error("Loser contact id {$this->argument('loser')} not found.");

            return self::FAILURE;
        }
        if ($winner->id === $loser->id) {
            $this->warn('Winner and loser are the same — no-op.');

            return self::SUCCESS;
        }

        $this->info("About to merge '{$loser->display_name}' (id {$loser->id}) into '{$winner->display_name}' (id {$winner->id}).");
        if (! $this->confirm('Proceed? This cannot be undone.', false)) {
            return self::SUCCESS;
        }

        ContactMerge::run($winner, $loser);
        $this->info("Merged. '{$loser->display_name}' deleted; every FK now points at '{$winner->display_name}'.");

        return self::SUCCESS;
    }
}
