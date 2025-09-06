<?php

declare(strict_types = 1);

namespace Centrex\Btyd;

class NelderMeadOptimizer
{
    protected $f;

    protected array $x0;

    protected int $maxIter;

    protected float $tol;

    public function __construct(callable $f, array $x0, int $maxIter = 1000, float $tol = 1e-6)
    {
        $this->f = $f;
        $this->x0 = array_values($x0);
        $this->maxIter = $maxIter;
        $this->tol = $tol;
    }

    public function minimize(): array
    {
        $n = count($this->x0);
        $scale = 0.05;
        $simplex = [];
        $vals = [];

        $simplex[0] = $this->x0;
        $vals[0] = ($this->f)($simplex[0]);

        for ($i = 1; $i <= $n; $i++) {
            $v = $this->x0;
            $v[$i - 1] = $v[$i - 1] != 0.0 ? $v[$i - 1] * (1 + $scale) : $scale;
            $simplex[$i] = $v;
            $vals[$i] = ($this->f)($v);
        }

        $alpha = 1.0;
        $gamma = 2.0;
        $rho = 0.5;
        $sigma = 0.5;
        $iter = 0;

        while ($iter < $this->maxIter) {
            array_multisort($vals, SORT_ASC, $simplex);

            $best = $simplex[0];
            $bestVal = $vals[0];
            $worst = $simplex[$n];
            $worstVal = $vals[$n];

            $centroid = array_fill(0, $n, 0.0);

            for ($i = 0; $i < $n; $i++) {
                for ($j = 0; $j < $n; $j++) {
                    $centroid[$j] += $simplex[$i][$j] / $n;
                }
            }

            $xr = [];

            for ($j = 0; $j < $n; $j++) {
                $xr[$j] = $centroid[$j] + $alpha * ($centroid[$j] - $worst[$j]);
            }
            $fr = ($this->f)($xr);

            if ($fr < $bestVal) {
                $xe = [];

                for ($j = 0; $j < $n; $j++) {
                    $xe[$j] = $centroid[$j] + $gamma * ($xr[$j] - $centroid[$j]);
                }
                $fe = ($this->f)($xe);

                if ($fe < $fr) {
                    $simplex[$n] = $xe;
                    $vals[$n] = $fe;
                } else {
                    $simplex[$n] = $xr;
                    $vals[$n] = $fr;
                }
            } elseif ($fr < $vals[$n - 1]) {
                $simplex[$n] = $xr;
                $vals[$n] = $fr;
            } else {
                if ($fr < $worstVal) {
                    $xc = [];

                    for ($j = 0; $j < $n; $j++) {
                        $xc[$j] = $centroid[$j] + $rho * ($xr[$j] - $centroid[$j]);
                    }
                    $fc = ($this->f)($xc);
                } else {
                    $xc = [];

                    for ($j = 0; $j < $n; $j++) {
                        $xc[$j] = $centroid[$j] + $rho * ($worst[$j] - $centroid[$j]);
                    }
                    $fc = ($this->f)($xc);
                }

                if ($fc < $worstVal) {
                    $simplex[$n] = $xc;
                    $vals[$n] = $fc;
                } else {
                    for ($i = 1; $i <= $n; $i++) {
                        for ($j = 0; $j < $n; $j++) {
                            $simplex[$i][$j] = $best[$j] + $sigma * ($simplex[$i][$j] - $best[$j]);
                        }
                        $vals[$i] = ($this->f)($simplex[$i]);
                    }
                }
            }

            $iter++;
            $meanVal = array_sum($vals) / count($vals);
            $ss = 0.0;

            foreach ($vals as $v) {
                $ss += ($v - $meanVal) ** 2;
            }
            $std = sqrt($ss / count($vals));

            if ($std < $this->tol) {
                break;
            }
        }

        array_multisort($vals, SORT_ASC, $simplex);

        return ['x' => $simplex[0], 'fval' => $vals[0], 'iter' => $iter];
    }
}
