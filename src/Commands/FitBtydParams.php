<?php

declare(strict_types = 1);

namespace Centrex\Btyd\Commands;

use Centrex\Btyd\Models\BtydParam;
use Illuminate\Console\Command;

class FitBtydParams extends Command
{
    protected $signature = 'btyd:fit';

    protected $description = 'Fit BG/NBD and Gamma-Gamma parameters (dummy implementation)';

    public function handle(): int
    {
        // Dummy fit - replace with real optimizer logic
        BtydParam::updateOrCreate(
            ['model' => 'bgnbd'],
            ['params' => [1.2, 3.4, 0.5, 2.3]],
        );

        BtydParam::updateOrCreate(
            ['model' => 'gamma_gamma'],
            ['params' => [6.0, 4.0, 15.0]],
        );

        $this->info('BTYD parameters fitted and stored.');

        return 0;
    }
}
