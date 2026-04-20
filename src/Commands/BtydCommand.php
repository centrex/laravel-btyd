<?php

declare(strict_types = 1);

namespace Centrex\Btyd\Commands;

use Centrex\Btyd\Btyd;
use Centrex\Btyd\Models\BtydParam;
use Illuminate\Console\Command;

class BtydCommand extends Command
{
    public $signature = 'btyd:status';

    public $description = 'Show the current BTYD model status and fitted parameters';

    public function handle(Btyd $btyd): int
    {
        $this->line('');
        $this->line('<fg=cyan;options=bold>BTYD Model Status</>');
        $this->line('');

        if (!BtydParam::isFitted()) {
            $this->warn('No fitted parameters found.');
            $this->line('Run: <comment>php artisan btyd:fit path/to/customers.json</comment>');
            $this->line('');

            return self::SUCCESS;
        }

        $fittedAt = BtydParam::fittedAt();
        $this->info('Parameters are fitted' . ($fittedAt ? ' — last fitted: ' . $fittedAt->diffForHumans() : '') . '.');
        $this->line('');

        $btyd->loadFromDb();

        $bgnbd = $btyd->getBgnbdParams();
        $gg = $btyd->getGgParams();

        if ($bgnbd !== null) {
            $this->table(['BG/NBD Parameter', 'Value', 'Interpretation'], [
                ['r', round($bgnbd['r'], 6), 'Transaction rate — gamma shape'],
                ['alpha', round($bgnbd['alpha'], 6), 'Transaction rate — gamma scale'],
                ['a', round($bgnbd['a'], 6), 'Dropout rate — beta shape (active)'],
                ['b', round($bgnbd['b'], 6), 'Dropout rate — beta scale (inactive)'],
            ]);
            $this->line('');
        }

        if ($gg !== null) {
            $this->table(['Gamma-Gamma Parameter', 'Value', 'Interpretation'], [
                ['p', round($gg['p'], 6), 'Spend variability shape'],
                ['q', round($gg['q'], 6), 'Spend rate — gamma shape'],
                ['v', round($gg['v'], 6), 'Spend rate — gamma scale'],
            ]);
            $this->line('');
        }

        $horizonMonths = (int) config('btyd.horizon_months', 12);
        $this->line("Default horizon: <comment>{$horizonMonths} months</comment>");
        $this->line('Min customers for fitting: <comment>' . config('btyd.min_customers', 10) . '</comment>');
        $this->line('');

        return self::SUCCESS;
    }
}
