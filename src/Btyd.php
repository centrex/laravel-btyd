<?php

declare(strict_types = 1);

namespace Centrex\Btyd;

use Carbon\Carbon;
use Centrex\Btyd\Models\BtydParam;
use InvalidArgumentException;
use MathPHP\Functions\Special;

class Btyd
{
    protected ?array $bgnbdParams = null;

    protected ?array $ggParams = null;

    /* -------------------------
     * Param management
     * ------------------------- */

    public function loadFromDb(): static
    {
        $bgnbd = BtydParam::getParams('bgnbd');
        $gg    = BtydParam::getParams('gamma_gamma');

        if ($bgnbd !== null) {
            $this->bgnbdParams = $this->normaliseNamedParams($bgnbd, ['r', 'alpha', 'a', 'b']);
        }

        if ($gg !== null) {
            $this->ggParams = $this->normaliseNamedParams($gg, ['p', 'q', 'v']);
        }

        return $this;
    }

    public function setBgnbdParams(array $params): static
    {
        $this->bgnbdParams = $this->normaliseNamedParams($params, ['r', 'alpha', 'a', 'b']);

        return $this;
    }

    public function setGgParams(array $params): static
    {
        $this->ggParams = $this->normaliseNamedParams($params, ['p', 'q', 'v']);

        return $this;
    }

    public function getBgnbdParams(): ?array
    {
        return $this->bgnbdParams;
    }

    public function getGgParams(): ?array
    {
        return $this->ggParams;
    }

    public function isReady(): bool
    {
        return $this->bgnbdParams !== null && $this->ggParams !== null;
    }

    public function isBgnbdReady(): bool
    {
        return $this->bgnbdParams !== null;
    }

    /* -------------------------
     * Data -> summary helper
     * ------------------------- */

    /**
     * Convert transaction rows into a BTYD summary for one customer.
     * $transactions: [['date' => Carbon|string, 'amount' => float], ...]
     */
    public static function transactionsToSummary(array $transactions, ?Carbon $observationEnd = null): array
    {
        $observationEnd ??= Carbon::now();

        if ($transactions === []) {
            return [
                'frequency'      => 0,
                'recency'        => 0.0,
                'T'              => 0.0,
                'monetary'       => 0.0,
                'n_transactions' => 0,
                'total_revenue'  => 0.0,
            ];
        }

        $tx = array_map(function (array $t): array {
            $date = $t['date'] instanceof Carbon ? $t['date'] : Carbon::parse($t['date']);

            return ['date' => $date, 'amount' => (float) $t['amount']];
        }, $transactions);

        usort($tx, static fn (array $a, array $b): int => $a['date']->lt($b['date']) ? -1 : 1);

        $first = $tx[0]['date'];
        $last  = $tx[count($tx) - 1]['date'];
        $n     = count($tx);

        $frequency    = max(0, $n - 1);
        $recencyDays  = (float) $first->diffInDays($last);
        $Tdays        = (float) $first->diffInDays($observationEnd);
        $totalRevenue = (float) array_sum(array_column($tx, 'amount'));
        $monetary     = $n > 0 ? $totalRevenue / $n : 0.0;

        return [
            'frequency'      => (int) $frequency,
            'recency'        => $recencyDays,
            'T'              => max($Tdays, $recencyDays + 1e-6),
            'monetary'       => $monetary,
            'n_transactions' => $n,
            'total_revenue'  => $totalRevenue,
        ];
    }

    /* -------------------------
     * Fit BG/NBD
     * ------------------------- */

    /**
     * Fit BG/NBD params from cohort summaries and persist to DB.
     * $summaries: [['frequency'=>int,'recency'=>float,'T'=>float], ...]
     */
    public function fitBgNbd(array $summaries, ?array $initial = null, bool $persist = true): array
    {
        if ($summaries === []) {
            throw new InvalidArgumentException('Cohort summaries required.');
        }

        $minCustomers = (int) config('btyd.min_customers', 10);

        if (count($summaries) < $minCustomers) {
            throw new InvalidArgumentException(
                sprintf('At least %d customers required to fit BG/NBD. Got %d.', $minCustomers, count($summaries)),
            );
        }

        $data = array_map(static fn (array $s): array => [
            'x'   => (int) ($s['frequency'] ?? 0),
            't_x' => (float) ($s['recency'] ?? 0.0),
            'T'   => (float) ($s['T'] ?? 0.0),
        ], $summaries);

        $defaults = config('btyd.bgnbd_initial', ['r' => 0.5, 'alpha' => 1.0, 'a' => 1.0, 'b' => 1.0]);
        $init     = $initial ?? $defaults;

        $maxIter = (int) config('btyd.optimizer.max_iter', 2000);
        $tol     = (float) config('btyd.optimizer.tol', 1e-6);

        $obj = function (array $params) use ($data): float {
            [$r, $alpha, $a, $b] = $params;

            if ($r <= 0 || $alpha <= 0 || $a <= 0 || $b <= 0) {
                return 1e100;
            }

            return $this->bgnbdNegLogLikelihood($data, $r, $alpha, $a, $b);
        };

        $x0       = [$init['r'], $init['alpha'], $init['a'], $init['b']];
        $optimizer = new NelderMeadOptimizer($obj, $x0, $maxIter, $tol);
        $res      = $optimizer->minimize();

        [$r, $alpha, $a, $b] = $res['x'];

        $this->bgnbdParams = [
            'r'     => max(1e-8, $r),
            'alpha' => max(1e-8, $alpha),
            'a'     => max(1e-8, $a),
            'b'     => max(1e-8, $b),
        ];

        if ($persist) {
            BtydParam::store('bgnbd', $this->bgnbdParams);
        }

        return $this->bgnbdParams;
    }

    /**
     * BG/NBD negative log-likelihood. data rows: ['x','t_x','T']
     */
    protected function bgnbdNegLogLikelihood(array $data, float $r, float $alpha, float $a, float $b): float
    {
        $ll = 0.0;

        foreach ($data as $row) {
            $x   = $row['x'];
            $t_x = $row['t_x'];
            $T   = $row['T'];

            $lnA  = Special::logGamma($r + $x) - Special::logGamma($r) - Special::logGamma($x + 1);
            $lnA += $r * log($alpha) - ($r + $x) * log($alpha + $T);
            $lnB  = $this->lnBeta($a + 1, $b + $x) - $this->lnBeta($a, $b);

            $ll_i = $lnA + $lnB;

            if (!is_finite($ll_i)) {
                $ll_i = -1e6;
            }
            $ll += $ll_i;
        }

        return -$ll;
    }

    /* -------------------------
     * Fit Gamma-Gamma
     * ------------------------- */

    /**
     * Fit Gamma-Gamma on customers with frequency > 0 and persist to DB.
     * $summaries: [['frequency'=>int,'monetary'=>float], ...]
     */
    public function fitGammaGamma(array $summaries, ?array $initial = null, bool $persist = true): array
    {
        $data = array_values(array_filter($summaries, static fn (array $s): bool => ($s['frequency'] ?? 0) > 0));

        if ($data === []) {
            throw new InvalidArgumentException('Need at least one customer with frequency > 0.');
        }

        $fm = array_map(static fn (array $s): array => [(int) $s['frequency'], (float) $s['monetary']], $data);

        $defaults = config('btyd.gamma_gamma_initial', ['p' => 1.0, 'q' => 1.0, 'v' => 1.0]);
        $init     = $initial ?? $defaults;

        $maxIter = (int) config('btyd.optimizer.max_iter', 2000);
        $tol     = (float) config('btyd.optimizer.tol', 1e-6);

        $obj = function (array $params) use ($fm): float {
            [$p, $q, $v] = $params;

            if ($p <= 0 || $q <= 0 || $v <= 0) {
                return 1e100;
            }

            return $this->ggNegLogLikelihood($fm, $p, $q, $v);
        };

        $x0       = [$init['p'], $init['q'], $init['v']];
        $optimizer = new NelderMeadOptimizer($obj, $x0, $maxIter, $tol);
        $res      = $optimizer->minimize();

        [$p, $q, $v] = $res['x'];

        $this->ggParams = [
            'p' => max(1e-8, $p),
            'q' => max(1e-8, $q),
            'v' => max(1e-8, $v),
        ];

        if ($persist) {
            BtydParam::store('gamma_gamma', $this->ggParams);
        }

        return $this->ggParams;
    }

    protected function ggNegLogLikelihood(array $fm, float $p, float $q, float $v): float
    {
        $ll = 0.0;

        foreach ($fm as [$f, $m]) {
            $ll_i  = Special::logGamma($p + $f) - Special::logGamma($p);
            $ll_i += $p * log($q);
            $ll_i -= ($p + $f) * log($q + ($f * $m / $v) + 1e-12);
            $ll   += $ll_i;
        }

        return -$ll;
    }

    /* -------------------------
     * Predictors
     * ------------------------- */

    /**
     * Expected transactions over horizonMonths for a single customer summary.
     * Requires fitted BG/NBD params (call loadFromDb() or fitBgNbd() first).
     */
    public function expectedTransactions(array $customerSummary, int $horizonMonths = 12): float
    {
        if ($this->bgnbdParams === null) {
            throw new InvalidArgumentException('BG/NBD parameters not fitted. Call loadFromDb() or fitBgNbd() first.');
        }

        $r     = $this->bgnbdParams['r'];
        $alpha = $this->bgnbdParams['alpha'];
        $a     = $this->bgnbdParams['a'];
        $b     = $this->bgnbdParams['b'];

        $x            = (int) $customerSummary['frequency'];
        $t_x          = (float) $customerSummary['recency'];
        $T            = (float) $customerSummary['T'];
        $tFutureDays  = $horizonMonths * 30.44;

        $numer  = ($r + $x) / ($alpha + $T);
        $pAlive = $this->probAlive($x, $t_x, $T, $r, $alpha, $a, $b);

        return max(0.0, $numer * $tFutureDays * $pAlive);
    }

    /**
     * Expected monetary value per transaction using Gamma-Gamma.
     * Requires fitted Gamma-Gamma params.
     */
    public function expectedMonetary(array $customerSummary): float
    {
        if ($this->ggParams === null) {
            throw new InvalidArgumentException('Gamma-Gamma parameters not fitted. Call loadFromDb() or fitGammaGamma() first.');
        }

        $p = $this->ggParams['p'];
        $q = $this->ggParams['q'];

        $f = (int) $customerSummary['frequency'];
        $m = (float) $customerSummary['monetary'];

        if ($f <= 0) {
            return $m;
        }

        $den = max(1e-8, $q + $f - 1);

        return (float) (($p + $f) / $den) * $m;
    }

    /**
     * Compute CLV for a customer summary over a horizon in months.
     */
    public function customerClv(array $customerSummary, int $horizonMonths = 12): float
    {
        $expTx  = $this->expectedTransactions($customerSummary, $horizonMonths);
        $expMon = $this->expectedMonetary($customerSummary);

        return round($expTx * $expMon, 2);
    }

    /**
     * Probability this customer is still alive given their purchase history.
     */
    public function probabilityAlive(array $customerSummary): float
    {
        if ($this->bgnbdParams === null) {
            throw new InvalidArgumentException('BG/NBD parameters not fitted. Call loadFromDb() or fitBgNbd() first.');
        }

        return $this->probAlive(
            (int) $customerSummary['frequency'],
            (float) $customerSummary['recency'],
            (float) $customerSummary['T'],
            $this->bgnbdParams['r'],
            $this->bgnbdParams['alpha'],
            $this->bgnbdParams['a'],
            $this->bgnbdParams['b'],
        );
    }

    /* -------------------------
     * Helpers
     * ------------------------- */

    protected function lnBeta(float $a, float $b): float
    {
        return Special::logGamma($a) + Special::logGamma($b) - Special::logGamma($a + $b);
    }

    protected function probAlive(int $x, float $t_x, float $T, float $r, float $alpha, float $a, float $b): float
    {
        $denTerm = ($b + $x - 1) > 0 ? ($b + $x - 1) : ($b + $x + 1e-8);
        $ratio   = (($alpha + $T) / ($alpha + $t_x + 1e-8)) ** ($r + $x);
        $val     = 1.0 / (1.0 + ($a / $denTerm) * $ratio);

        return min(1.0, max(0.0, $val));
    }

    private function normaliseNamedParams(array $params, array $keys): array
    {
        if (array_key_exists($keys[0], $params)) {
            return $params;
        }

        $values = array_values($params);
        $result = [];

        foreach ($keys as $i => $key) {
            $result[$key] = $values[$i] ?? 1.0;
        }

        return $result;
    }
}
