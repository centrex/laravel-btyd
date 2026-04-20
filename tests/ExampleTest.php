<?php

declare(strict_types = 1);

use Carbon\Carbon;
use Centrex\Btyd\Btyd;
use Centrex\Btyd\Models\BtydParam;

// -------------------------------------------------------
// transactionsToSummary
// -------------------------------------------------------

it('converts an empty transaction list to a zero summary', function (): void {
    $summary = Btyd::transactionsToSummary([]);

    expect($summary['frequency'])->toBe(0)
        ->and($summary['recency'])->toBe(0.0)
        ->and($summary['T'])->toBe(0.0)
        ->and($summary['monetary'])->toBe(0.0)
        ->and($summary['n_transactions'])->toBe(0)
        ->and($summary['total_revenue'])->toBe(0.0);
});

it('computes frequency as repeat purchases (n - 1)', function (): void {
    $now = Carbon::now();
    $txs = [
        ['date' => $now->copy()->subDays(365), 'amount' => 1000],
        ['date' => $now->copy()->subDays(200), 'amount' => 1500],
        ['date' => $now->copy()->subDays(100), 'amount' => 2000],
    ];

    $summary = Btyd::transactionsToSummary($txs, $now);

    expect($summary['frequency'])->toBe(2)
        ->and($summary['n_transactions'])->toBe(3)
        ->and($summary['total_revenue'])->toBe(4500.0)
        ->and($summary['monetary'])->toBe(1500.0);
});

it('computes recency as days between first and last purchase', function (): void {
    $now = Carbon::now();
    $first = $now->copy()->subDays(300);
    $last = $now->copy()->subDays(50);

    $txs = [
        ['date' => $first, 'amount' => 500],
        ['date' => $last,  'amount' => 500],
    ];

    $summary = Btyd::transactionsToSummary($txs, $now);

    expect($summary['recency'])->toBeGreaterThan(240.0)
        ->and($summary['T'])->toBeGreaterThan(295.0);
});

it('handles a single transaction with zero frequency', function (): void {
    $txs = [['date' => Carbon::now()->subDays(180), 'amount' => 3000]];

    $summary = Btyd::transactionsToSummary($txs);

    expect($summary['frequency'])->toBe(0)
        ->and($summary['monetary'])->toBe(3000.0)
        ->and($summary['T'])->toBeGreaterThan(170.0);
});

it('parses string dates correctly', function (): void {
    $txs = [
        ['date' => '2024-01-01', 'amount' => 1000],
        ['date' => '2024-07-01', 'amount' => 2000],
    ];

    $summary = Btyd::transactionsToSummary($txs, Carbon::parse('2025-01-01'));

    expect($summary['frequency'])->toBe(1)
        ->and($summary['recency'])->toBeGreaterThan(180.0)
        ->and($summary['T'])->toBeGreaterThan(360.0);
});

// -------------------------------------------------------
// Param management
// -------------------------------------------------------

it('loads named params from array correctly', function (): void {
    $btyd = new Btyd();
    $btyd->setBgnbdParams(['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);
    $btyd->setGgParams(['p' => 6.25, 'q' => 3.74, 'v' => 15.44]);

    expect($btyd->isReady())->toBeTrue()
        ->and($btyd->isBgnbdReady())->toBeTrue()
        ->and($btyd->getBgnbdParams()['r'])->toBe(0.24)
        ->and($btyd->getGgParams()['p'])->toBe(6.25);
});

it('normalises positional arrays into named params', function (): void {
    $btyd = new Btyd();
    $btyd->setBgnbdParams([0.24, 4.41, 0.79, 2.43]);
    $btyd->setGgParams([6.25, 3.74, 15.44]);

    expect($btyd->getBgnbdParams())->toHaveKey('r')
        ->and($btyd->getBgnbdParams()['alpha'])->toBe(4.41)
        ->and($btyd->getGgParams())->toHaveKey('p')
        ->and($btyd->getGgParams()['v'])->toBe(15.44);
});

it('is not ready when params are missing', function (): void {
    $btyd = new Btyd();

    expect($btyd->isReady())->toBeFalse()
        ->and($btyd->isBgnbdReady())->toBeFalse();
});

// -------------------------------------------------------
// BtydParam model
// -------------------------------------------------------

it('stores and retrieves params from the database', function (): void {
    BtydParam::store('bgnbd', ['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);
    BtydParam::store('gamma_gamma', ['p' => 6.25, 'q' => 3.74, 'v' => 15.44]);

    $bgnbd = BtydParam::getParams('bgnbd');
    $gg = BtydParam::getParams('gamma_gamma');

    expect($bgnbd['r'])->toBe(0.24)
        ->and($gg['p'])->toBe(6.25);
});

it('sets fitted_at when storing params', function (): void {
    BtydParam::store('bgnbd', ['r' => 0.5, 'alpha' => 1.0, 'a' => 1.0, 'b' => 1.0]);

    $record = BtydParam::where('model', 'bgnbd')->first();

    expect($record->fitted_at)->not->toBeNull();
});

it('updates existing params on re-fit', function (): void {
    BtydParam::store('bgnbd', ['r' => 0.5, 'alpha' => 1.0, 'a' => 1.0, 'b' => 1.0]);
    BtydParam::store('bgnbd', ['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);

    expect(BtydParam::where('model', 'bgnbd')->count())->toBe(1)
        ->and(BtydParam::getParams('bgnbd')['r'])->toBe(0.24);
});

it('reports isFitted correctly', function (): void {
    expect(BtydParam::isFitted())->toBeFalse();

    BtydParam::store('bgnbd', ['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);
    expect(BtydParam::isFitted())->toBeFalse();

    BtydParam::store('gamma_gamma', ['p' => 6.25, 'q' => 3.74, 'v' => 15.44]);
    expect(BtydParam::isFitted())->toBeTrue();
});

// -------------------------------------------------------
// loadFromDb
// -------------------------------------------------------

it('loads params from the database into the Btyd instance', function (): void {
    BtydParam::store('bgnbd', ['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);
    BtydParam::store('gamma_gamma', ['p' => 6.25, 'q' => 3.74, 'v' => 15.44]);

    $btyd = (new Btyd())->loadFromDb();

    expect($btyd->isReady())->toBeTrue()
        ->and($btyd->getBgnbdParams()['r'])->toBe(0.24)
        ->and($btyd->getGgParams()['p'])->toBe(6.25);
});

it('stays not ready when database has no params', function (): void {
    $btyd = (new Btyd())->loadFromDb();

    expect($btyd->isReady())->toBeFalse();
});

// -------------------------------------------------------
// expectedTransactions / expectedMonetary / customerClv
// -------------------------------------------------------

it('predicts more transactions for a recently active customer', function (): void {
    $btyd = (new Btyd())
        ->setBgnbdParams(['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43])
        ->setGgParams(['p' => 6.25, 'q' => 3.74, 'v' => 15.44]);

    $active = ['frequency' => 5, 'recency' => 300.0, 'T' => 365.0, 'monetary' => 5000.0];
    $inactive = ['frequency' => 1, 'recency' => 10.0,  'T' => 365.0, 'monetary' => 5000.0];

    $txActive = $btyd->expectedTransactions($active, 12);
    $txInactive = $btyd->expectedTransactions($inactive, 12);

    expect($txActive)->toBeGreaterThan($txInactive);
});

it('scales expected transactions proportionally with horizon', function (): void {
    $btyd = (new Btyd())
        ->setBgnbdParams(['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43])
        ->setGgParams(['p' => 6.25, 'q' => 3.74, 'v' => 15.44]);

    $summary = ['frequency' => 3, 'recency' => 200.0, 'T' => 365.0, 'monetary' => 1000.0];

    $tx12 = $btyd->expectedTransactions($summary, 12);
    $tx6 = $btyd->expectedTransactions($summary, 6);

    expect($tx12)->toBeGreaterThan($tx6);
});

it('returns non-negative CLV', function (): void {
    $btyd = (new Btyd())
        ->setBgnbdParams(['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43])
        ->setGgParams(['p' => 6.25, 'q' => 3.74, 'v' => 15.44]);

    $summary = ['frequency' => 2, 'recency' => 150.0, 'T' => 300.0, 'monetary' => 8000.0];

    expect($btyd->customerClv($summary, 12))->toBeGreaterThanOrEqual(0.0);
});

it('throws when expected transactions called without bgnbd params', function (): void {
    $btyd = new Btyd();

    expect(fn () => $btyd->expectedTransactions(['frequency' => 1, 'recency' => 100.0, 'T' => 200.0, 'monetary' => 500.0]))
        ->toThrow(InvalidArgumentException::class);
});

it('throws when expected monetary called without gamma-gamma params', function (): void {
    $btyd = (new Btyd())->setBgnbdParams(['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);

    expect(fn () => $btyd->expectedMonetary(['frequency' => 2, 'monetary' => 5000.0]))
        ->toThrow(InvalidArgumentException::class);
});

// -------------------------------------------------------
// probabilityAlive
// -------------------------------------------------------

it('gives higher p_alive to frequent recent buyers', function (): void {
    $btyd = (new Btyd())
        ->setBgnbdParams(['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);

    $loyal = ['frequency' => 8, 'recency' => 350.0, 'T' => 365.0];
    $churned = ['frequency' => 1, 'recency' => 5.0,   'T' => 365.0];

    expect($btyd->probabilityAlive($loyal))->toBeGreaterThan($btyd->probabilityAlive($churned));
});

it('returns p_alive between 0 and 1', function (): void {
    $btyd = (new Btyd())
        ->setBgnbdParams(['r' => 0.24, 'alpha' => 4.41, 'a' => 0.79, 'b' => 2.43]);

    $summary = ['frequency' => 3, 'recency' => 180.0, 'T' => 365.0];

    $p = $btyd->probabilityAlive($summary);

    expect($p)->toBeGreaterThanOrEqual(0.0)->toBeLessThanOrEqual(1.0);
});

// -------------------------------------------------------
// fitBgNbd / fitGammaGamma (integration, small cohort)
// -------------------------------------------------------

it('fits bgnbd params that produce positive values', function (): void {
    $summaries = [
        ['frequency' => 4, 'recency' => 300.0, 'T' => 365.0, 'monetary' => 5000.0],
        ['frequency' => 1, 'recency' => 100.0, 'T' => 365.0, 'monetary' => 3000.0],
        ['frequency' => 0, 'recency' => 0.0,   'T' => 180.0, 'monetary' => 2000.0],
        ['frequency' => 6, 'recency' => 340.0, 'T' => 365.0, 'monetary' => 8000.0],
        ['frequency' => 2, 'recency' => 200.0, 'T' => 300.0, 'monetary' => 4500.0],
    ];

    $btyd = new Btyd();
    $params = $btyd->fitBgNbd($summaries, null, false);

    expect($params['r'])->toBeGreaterThan(0.0)
        ->and($params['alpha'])->toBeGreaterThan(0.0)
        ->and($params['a'])->toBeGreaterThan(0.0)
        ->and($params['b'])->toBeGreaterThan(0.0);
});

it('fits gamma-gamma params that produce positive values', function (): void {
    $summaries = [
        ['frequency' => 3, 'monetary' => 5000.0],
        ['frequency' => 1, 'monetary' => 3000.0],
        ['frequency' => 5, 'monetary' => 8000.0],
        ['frequency' => 2, 'monetary' => 4500.0],
        ['frequency' => 4, 'monetary' => 6000.0],
    ];

    $btyd = new Btyd();
    $params = $btyd->fitGammaGamma($summaries, null, false);

    expect($params['p'])->toBeGreaterThan(0.0)
        ->and($params['q'])->toBeGreaterThan(0.0)
        ->and($params['v'])->toBeGreaterThan(0.0);
});

it('persists fitted params to database with fitted_at set', function (): void {
    $summaries = [
        ['frequency' => 4, 'recency' => 300.0, 'T' => 365.0, 'monetary' => 5000.0],
        ['frequency' => 1, 'recency' => 100.0, 'T' => 365.0, 'monetary' => 3000.0],
        ['frequency' => 0, 'recency' => 0.0,   'T' => 180.0, 'monetary' => 2000.0],
        ['frequency' => 6, 'recency' => 340.0, 'T' => 365.0, 'monetary' => 8000.0],
        ['frequency' => 2, 'recency' => 200.0, 'T' => 300.0, 'monetary' => 4500.0],
    ];

    $btyd = new Btyd();
    $btyd->fitBgNbd($summaries, null, true);
    $btyd->fitGammaGamma($summaries, null, true);

    expect(BtydParam::isFitted())->toBeTrue()
        ->and(BtydParam::fittedAt())->not->toBeNull();
});

it('rejects bgnbd fit with fewer customers than minimum', function (): void {
    config()->set('btyd.min_customers', 10);

    $summaries = [
        ['frequency' => 2, 'recency' => 100.0, 'T' => 200.0, 'monetary' => 500.0],
    ];

    expect(fn () => (new Btyd())->fitBgNbd($summaries))->toThrow(InvalidArgumentException::class);
});

it('rejects gamma-gamma fit when no customers have repeat purchases', function (): void {
    $summaries = [
        ['frequency' => 0, 'monetary' => 1000.0],
        ['frequency' => 0, 'monetary' => 2000.0],
    ];

    expect(fn () => (new Btyd())->fitGammaGamma($summaries))->toThrow(InvalidArgumentException::class);
});

// -------------------------------------------------------
// End-to-end: fit → loadFromDb → predict
// -------------------------------------------------------

it('can fit params, load from db, and produce a clv prediction', function (): void {
    $summaries = [
        ['frequency' => 4, 'recency' => 300.0, 'T' => 365.0, 'monetary' => 5000.0],
        ['frequency' => 1, 'recency' => 100.0, 'T' => 365.0, 'monetary' => 3000.0],
        ['frequency' => 0, 'recency' => 0.0,   'T' => 180.0, 'monetary' => 2000.0],
        ['frequency' => 6, 'recency' => 340.0, 'T' => 365.0, 'monetary' => 8000.0],
        ['frequency' => 2, 'recency' => 200.0, 'T' => 300.0, 'monetary' => 4500.0],
    ];

    $fitter = new Btyd();
    $fitter->fitBgNbd($summaries);
    $fitter->fitGammaGamma($summaries);

    $predictor = (new Btyd())->loadFromDb();

    expect($predictor->isReady())->toBeTrue();

    $clv = $predictor->customerClv(
        ['frequency' => 3, 'recency' => 200.0, 'T' => 300.0, 'monetary' => 5000.0],
        12,
    );

    expect($clv)->toBeGreaterThanOrEqual(0.0);
});
