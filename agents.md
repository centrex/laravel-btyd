# agents.md

## Agent Guidance — laravel-btyd

### Package Purpose
Buy-Til-You-Die (BG/NBD + Gamma-Gamma) customer lifetime value (CLV) prediction. Fits statistical models to transaction history and forecasts future purchase frequency and monetary value.

### Before Making Changes
- Read `src/Btyd.php` — main public API for CLV prediction
- Read `src/NelderMeadOptimizer.php` — numerical optimizer used for parameter fitting
- Read `src/Models/` — data models for storing transaction/customer data
- Understand the BG/NBD model math before touching optimizer or prediction logic

### Domain Knowledge
- **BG/NBD model**: models "alive/dead" customer state + purchase frequency using recency, frequency, and T (time since first purchase)
- **Gamma-Gamma model**: models average transaction value using frequency and monetary value
- **Nelder-Mead**: derivative-free optimization algorithm — do not replace with gradient-based methods without benchmarking
- Input data must be aggregated per customer (not raw transactions)

### Common Tasks

**Updating prediction logic**
1. Any change to `Btyd.php` prediction methods requires verifying model outputs against known CLV benchmarks
2. Run tests to confirm expected CLV ranges haven't shifted
3. If changing optimizer parameters (initial guess, tolerance), document why

**Adding a new model variant**
1. Create a new class in `src/` implementing a common interface (if one exists)
2. Make it selectable via config, not hardcoded switching
3. Add dedicated tests with known input/output pairs

**Adding data storage**
- Add migrations if storing fitted parameters or predictions
- Fitted model parameters should be stored (not refitted on every request)

### Testing
```sh
composer test:unit        # pest — includes model prediction accuracy tests
composer test:types       # phpstan
composer test:lint        # pint
```

For numeric correctness tests, use approximate equality:
```php
expect($predictedClv)->toBeBetween(90.0, 110.0);
```

### Safe Operations
- Adding new convenience methods to `Btyd.php`
- Adding data models / migrations
- Improving performance of the optimizer (same outputs)
- Adding tests with known CLV values

### Risky Operations — Confirm Before Doing
- Changing NelderMead convergence criteria or initial parameter bounds
- Modifying the BG/NBD likelihood function
- Changing how recency/frequency/T are calculated from raw transactions

### Do Not
- Return CLV as negative values — add a max(0, result) guard
- Run optimizer in a web request without queuing (it's CPU-intensive)
- Skip `declare(strict_types=1)` in any new file
