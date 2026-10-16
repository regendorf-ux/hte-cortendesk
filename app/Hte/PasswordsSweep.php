<?php

namespace App\Hte;

use Illuminate\Console\Command;

/** HTE (2026-10-16): change due passwords and keep the automatic address books in step (every minute). */
class PasswordsSweep extends Command
{
    protected $signature = 'hte:passwords';

    protected $description = 'HTE: per-computer passwords: change the due ones, refresh the automatic address books';

    public function handle(): int
    {
        $s = Passwords::sweep();
        if ($s['issued'] || $s['books']) {
            $this->info("issued {$s['issued']}, book entries written {$s['books']}");
        }

        return self::SUCCESS;
    }
}
