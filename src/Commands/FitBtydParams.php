<?php

declare(strict_types = 1);

namespace Centrex\Btyd\Commands;

use Centrex\Btyd\Btyd;
use Illuminate\Console\Command;
use InvalidArgumentException;

class FitBtydParams extends Command
{
    protected $signature = 'btyd:fit
        {file : Path to JSON file with customer transaction data}
        {--summaries : Input is already in summary format (skip transactionsToSummary step)}
        {--no-persist : Fit params but do not save to database}
        {--horizon=12 : Default prediction horizon in months (for display only)}';

    protected $description = 'Fit BG/NBD and Gamma-Gamma parameters from customer transaction history';

    public function handle(Btyd $btyd): int
    {
        $file = $this->argument('file');

        if (!file_exists($file)) {
            $this->error("File not found: {$file}");
            $this->showUsage();

            return self::FAILURE;
        }

        $raw = json_decode((string) file_get_contents($file), true);

        if (!is_array($raw) || $raw === []) {
            $this->error('Invalid JSON: expected a non-empty array.');
            $this->showUsage();

            return self::FAILURE;
        }

        $minCustomers = (int) config('btyd.min_customers', 10);

        if (count($raw) < $minCustomers) {
            $this->warn(sprintf(
                'Only %d customers provided. Recommended minimum is %d for reliable fits.',
                count($raw),
                $minCustomers,
            ));
        }

        $persist = !$this->option('no-persist');

        if ($this->option('summaries')) {
            $summaries = $raw;
        } else {
            $this->line('Converting transaction arrays to BTYD summaries...');
            $summaries = array_map(
                static fn (array $txs): array => Btyd::transactionsToSummary($txs),
                $raw,
            );
        }

        $this->line(sprintf('Fitting BG/NBD on %d customers...', count($summaries)));

        try {
            $bgnbd = $btyd->fitBgNbd($summaries, null, $persist);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(sprintf('Fitting Gamma-Gamma on customers with repeat purchases...'));

        try {
            $gg = $btyd->fitGammaGamma($summaries, null, $persist);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Parameters fitted' . ($persist ? ' and saved to database.' : ' (not persisted).'));
        $this->newLine();

        $this->table(['Model', 'Parameter', 'Value'], [
            ['BG/NBD', 'r (transaction rate shape)', round($bgnbd['r'], 6)],
            ['BG/NBD', 'alpha (transaction rate scale)', round($bgnbd['alpha'], 6)],
            ['BG/NBD', 'a (churn rate shape)', round($bgnbd['a'], 6)],
            ['BG/NBD', 'b (churn rate scale)', round($bgnbd['b'], 6)],
            ['Gamma-Gamma', 'p (spend shape)', round($gg['p'], 6)],
            ['Gamma-Gamma', 'q (spend rate shape)', round($gg['q'], 6)],
            ['Gamma-Gamma', 'v (spend rate scale)', round($gg['v'], 6)],
        ]);

        return self::SUCCESS;
    }

    private function showUsage(): void
    {
        $this->newLine();
        $this->line('Expected JSON formats:');
        $this->newLine();
        $this->line('<comment>Transaction arrays</comment> (default):');
        $this->line('[');
        $this->line('  [{"date":"2024-01-15","amount":5000},{"date":"2024-06-20","amount":7500}],');
        $this->line('  [{"date":"2023-11-01","amount":3000}],');
        $this->line('  ...');
        $this->line(']');
        $this->newLine();
        $this->line('<comment>Pre-computed summaries</comment> (with --summaries flag):');
        $this->line('[');
        $this->line('  {"frequency":3,"recency":180.5,"T":365.0,"monetary":5000.0},');
        $this->line('  {"frequency":0,"recency":0,"T":120.0,"monetary":3000.0},');
        $this->line('  ...');
        $this->line(']');
    }
}
