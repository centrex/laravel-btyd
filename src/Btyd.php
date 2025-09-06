<?php

declare(strict_types = 1);

namespace Centrex\Btyd;

use Carbon\Carbon;
use InvalidArgumentException;
use MathPHP\Functions\Special;

/**
 * Btyd (Buy 'Til You Die) customer lifetime value model.
 *
 * - Fits BG/NBD (r, alpha, a, b) using MLE on cohort summaries.
 * - Fits Gamma-Gamma (p, q, v) using MLE on customers with freq > 0.
 * - Predicts expected transactions (BG/NBD) and expected monetary value (Gamma-Gamma).
 *
 * Usage:
 *  $btyd = new BTYDService();
 *  $btyd->fitBgNbd($cohortSummaries);   // cohortSummaries = [ ['frequency'=>..,'recency'=>..,'T'=>..], ... ]
 *  $btyd->fitGammaGamma($monetarySummaries); // monetarySummaries = [ ['frequency'=>..,'monetary'=>..], ... ]
 *  $clv = $btyd->customerClv($customerSummary, 12);
 */
class Btyd
{
    protected ?array $bgnbdParams = null;

    protected ?array $ggParams = null;

    /* -------------------------
     * Data -> summary helper
     * ------------------------- */

    /**
     * Convert transaction rows into BTYD summary.
     * $transactions: array of ['date' => Carbon|string, 'amount' => float]
     * $observationEnd: Carbon|null (defaults to now())
     */
    public static function transactionsToSummary(array $transactions, ?Carbon $observationEnd = null): array
    {
        $observationEnd ??= Carbon::now();

        if (empty($transactions)) {
            return [
                'frequency'      => 0,
                'recency'        => 0.0,
                'T'              => 0.0,
                'monetary'       => 0.0,
                'n_transactions' => 0,
                'total_revenue'  => 0.0,
            ];
        }

        // normalize dates
        $tx = array_map(function ($t) {
            $date = $t['date'] instanceof Carbon ? $t['date'] : Carbon::parse($t['date']);

            return ['date' => $date, 'amount' => (float) $t['amount']];
        }, $transactions);

        usort($tx, fn ($a, $b) => $a['date']->lt($b['date']) ? -1 : 1);

        $first = $tx[0]['date'];
        $last = $tx[count($tx) - 1]['date'];
        $n = count($tx);

        $frequency = max(0, $n - 1);
        $recencyDays = (float) $first->diffInDays($last);
        $Tdays = (float) $first->diffInDays($observationEnd);
        $totalRevenue = array_sum(array_column($tx, 'amount'));
        $monetary = $n > 0 ? $totalRevenue / $n : 0.0;

        return [
            'frequency'      => (int) $frequency,
            'recency'        => $recencyDays,
            'T'              => $Tdays,
            'monetary'       => (float) $monetary,
            'n_transactions' => $n,
            'total_revenue'  => (float) $totalRevenue,
        ];
    }

    /* -------------------------
     * Fit BG/NBD
     * ------------------------- */

    /**
     * Fit BG/NBD params from cohort summaries:
     * $summaries: [ ['frequency'=>int,'recency'=>float,'T'=>float], ... ]
     */
    public function fitBgNbd(array $summaries, ?array $initial = null): array
    {
        if (empty($summaries)) {
            throw new InvalidArgumentException('Cohort summaries required.');
        }

        $data = array_map(fn ($s) => [
            'x'   => (int) ($s['frequency'] ?? 0),
            't_x' => (float) ($s['recency'] ?? 0.0),
            'T'   => (float) ($s['T'] ?? 0.0),
        ], $summaries);

        $init = $initial ?? ['r' => 0.5, 'alpha' => 1.0, 'a' => 1.0, 'b' => 1.0];

        $obj = function (array $params) use ($data): float {
            [$r, $alpha, $a, $b] = $params;

            if ($r <= 0 || $alpha <= 0 || $a <= 0 || $b <= 0) {
                return 1e100;
            }

            return $this->bgnbdNegLogLikelihood($data, $r, $alpha, $a, $b);
        };

        $x0 = [$init['r'], $init['alpha'], $init['a'], $init['b']];
        $optimizer = new NelderMeadOptimizer($obj, $x0, 2000, 1e-6);
        $res = $optimizer->minimize();

        [$r, $alpha, $a, $b] = $res['x'];
        $this->bgnbdParams = ['r' => max(1e-8, $r), 'alpha' => max(1e-8, $alpha), 'a' => max(1e-8, $a), 'b' => max(1e-8, $b)];

        return $this->bgnbdParams;
    }

    /**
     * BG/NBD negative log-likelihood.
     * data rows: ['x','t_x','T']
     */
    protected function bgnbdNegLogLikelihood(array $data, float $r, float $alpha, float $a, float $b): float
    {
        $ll = 0.0;

        foreach ($data as $row) {
            $x = $row['x'];
            $t_x = $row['t_x'];
            $T = $row['T'];

            // Log-probability for x and t_x:
            // lnA = lnGamma(r + x) - lnGamma(r) - ln(x!) + r ln(alpha) - (r + x) ln(alpha + T)
            $lnA = Special::logGamma($r + $x) - Special::logGamma($r) - Special::logGamma($x + 1);
            $lnA += $r * log($alpha) - ($r + $x) * log($alpha + $T);

            // lnB = ln B(a + 1, b + x) - ln B(a, b)
            $lnB = $this->lnBeta($a + 1, $b + $x) - $this->lnBeta($a, $b);

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
     * Fit Gamma-Gamma on customers with frequency > 0
     * $summaries: [ ['frequency'=>int,'monetary'=>float], ... ]
     */
    public function fitGammaGamma(array $summaries, ?array $initial = null): array
    {
        $data = array_values(array_filter($summaries, fn ($s) => ($s['frequency'] ?? 0) > 0));

        if (empty($data)) {
            throw new InvalidArgumentException('Need at least one customer with frequency > 0.');
        }

        $fm = array_map(fn ($s) => [(int) $s['frequency'], (float) $s['monetary']], $data);

        $init = $initial ?? ['p' => 1.0, 'q' => 1.0, 'v' => 1.0];

        $obj = function (array $params) use ($fm): float {
            [$p, $q, $v] = $params;

            if ($p <= 0 || $q <= 0 || $v <= 0) {
                return 1e100;
            }

            return $this->ggNegLogLikelihood($fm, $p, $q, $v);
        };

        $x0 = [$init['p'], $init['q'], $init['v']];
        $optimizer = new NelderMeadOptimizer($obj, $x0, 2000, 1e-6);
        $res = $optimizer->minimize();

        [$p, $q, $v] = $res['x'];
        $this->ggParams = ['p' => max(1e-8, $p), 'q' => max(1e-8, $q), 'v' => max(1e-8, $v)];

        return $this->ggParams;
    }

    protected function ggNegLogLikelihood(array $fm, float $p, float $q, float $v): float
    {
        $ll = 0.0;

        foreach ($fm as [$f, $m]) {
            // Using commonly used log-likelihood approximation for gamma-gamma:
            // ll_i = lnGamma(p + f) - lnGamma(p) + p ln q - (p + f) ln (q + f * m / v)
            $ll_i = Special::logGamma($p + $f) - Special::logGamma($p);
            $ll_i += $p * log($q);
            $ll_i -= ($p + $f) * log($q + ($f * $m / $v) + 1e-12);
            $ll += $ll_i;
        }

        return -$ll;
    }

    /* -------------------------
     * Predictors
     * ------------------------- */

    /**
     * Expected transactions over horizonMonths (months) for a single customer summary.
     * Uses fitted BG/NBD params.
     */
    public function expectedTransactions(array $customerSummary, int $horizonMonths = 12): float
    {
        if ($this->bgnbdParams === null) {
            throw new InvalidArgumentException('BG/NBD parameters not fitted.');
        }

        $r = $this->bgnbdParams['r'];
        $alpha = $this->bgnbdParams['alpha'];
        $a = $this->bgnbdParams['a'];
        $b = $this->bgnbdParams['b'];

        $x = (int) $customerSummary['frequency'];
        $t_x = (float) $customerSummary['recency'];
        $T = (float) $customerSummary['T'];
        $t_future_days = $horizonMonths * 30.44;

        // Use conditional expectation approximation (Fader & Hardie)
        // E[X(t_future) | history] ≈ ( (r + x) / (alpha + T) ) * t_future * P(alive | history)
        $numer = ($r + $x) / ($alpha + $T);
        $pAlive = $this->probAlive($x, $t_x, $T, $r, $alpha, $a, $b);

        $expected = $numer * $t_future_days * $pAlive;

        return max(0.0, (float) $expected);
    }

    /**
     * Expected monetary value per transaction for customer using Gamma-Gamma.
     */
    public function expectedMonetary(array $customerSummary): float
    {
        if ($this->ggParams === null) {
            throw new InvalidArgumentException('Gamma-Gamma parameters not fitted.');
        }

        $p = $this->ggParams['p'];
        $q = $this->ggParams['q'];
        $v = $this->ggParams['v'];

        $f = (int) $customerSummary['frequency'];
        $m = (float) $customerSummary['monetary'];

        if ($f <= 0) {
            return $m;
        }

        // Using Gamma-Gamma expected conditional spend: (p + f) / (q + f - 1) * (m) (approx)
        $den = max(1e-8, ($q + $f - 1));

        return (float) (($p + $f) / $den) * $m;
    }

    /**
     * Compute CLV for customer summary and horizon in months.
     */
    public function customerClv(array $customerSummary, int $horizonMonths = 12): float
    {
        $expTx = $this->expectedTransactions($customerSummary, $horizonMonths);
        $expMon = $this->expectedMonetary($customerSummary);

        return round($expTx * $expMon, 2);
    }

    /* -------------------------
     * Helper functions
     * ------------------------- */

    protected function lnBeta(float $a, float $b): float
    {
        return Special::logGamma($a) + Special::logGamma($b) - Special::logGamma($a + $b);
    }

    protected function probAlive(int $x, float $t_x, float $T, float $r, float $alpha, float $a, float $b): float
    {
        // Using closed-form approximation:
        // p_alive = 1 / (1 + (a/(b + x - 1)) * ((alpha + T)/(alpha + t_x))^(r + x))
        $denTerm = ($b + $x - 1) > 0 ? ($b + $x - 1) : ($b + $x + 1e-8);
        $ratio = pow(($alpha + $T) / ($alpha + $t_x + 1e-8), ($r + $x));
        $val = 1.0 / (1.0 + ($a / $denTerm) * $ratio);

        return min(1.0, max(0.0, $val));
    }
}
