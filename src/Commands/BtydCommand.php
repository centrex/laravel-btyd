<?php

declare(strict_types = 1);

namespace Centrex\Btyd\Commands;

use Illuminate\Console\Command;

class BtydCommand extends Command
{
    public $signature = 'btyd';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
