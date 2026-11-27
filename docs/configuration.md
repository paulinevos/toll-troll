# Configuration and results

[Back to the README](../README.md)

## Evaluation settings

You can set defaults for your whole application, then override individual bridges as needed. To change the defaults, publish `config/toll-troll.php`:

```sh
php artisan vendor:publish --tag=toll-troll-config
```

| Setting | Default | Purpose |
| --- | --- | --- |
| `classifier` | `['provider' => null, 'model' => null]` | One classifier for all guards and branches. Include both keys. |
| `timeout` | `60` | Positive timeout in seconds per inference call, not for the whole evaluation. |
| `variants` | `0` | Number of additional paraphrases to assess alongside the original. |
| `paraphrasers` | `[['provider' => null, 'model' => null]]` | Rewrite engines, used round-robin. Must be nonempty when variants are enabled. |
| `injection_guard.enabled` | `true` | Add automatic injection screening. Explicit guards still run if disabled. |
| `injection_guard.pass` | `0.8` | Minimum P(pass) for an injection assessment to pass. |
| `injection_guard.fail` | `0.2` | An injection assessment fails when P(fail) ≥ 1 − this value. |

Classification supports Laravel AI provider configurations using the `typesafe`, `openai`, or `gemini` driver. A null provider selects `ai.default_for_classification`, not the text default. A null model selects the provider's default classification model. Text-generation fallbacks and generic OpenAI-compatible drivers are not supported classifiers.

Paraphrasing and annotation advice use Laravel AI's **text** defaults, independently of classification. Configure a text-capable provider for those features.

## Bridge-level overrides

Pass `new EvaluationConfig(...)` as a bridge's `config` argument. Null fields inherit the file settings; explicit `false`, `0`, and empty arrays do not.

| Constructor argument | Overrides |
| --- | --- |
| `classifier` | The entire `classifier` provider/model pair. |
| `classificationTimeout` | Classification timeout; otherwise inherits `timeout`. |
| `variants` | `variants`. |
| `paraphrasers` | The entire engine list, not individual entries. |
| `paraphraseTimeout` | Rewrite timeout; otherwise inherits `timeout`. |
| `injectionGuard` | `injection_guard.enabled`. |
| `injectionPassThreshold` | `injection_guard.pass`. |
| `injectionFailThreshold` | `injection_guard.fail`. |

Include both `provider` and `model` in each engine entry. Explicit null engine values select SDK defaults rather than inheriting that part of the global engine. Classification settings apply to every guard and branch; there are no per-policy classifier overrides or configuration attributes.

For one-off evaluations without a named bridge:

```php
use Vos\TollTroll\Context;
use Vos\TollTroll\EvaluationConfig;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;

$result = app(TollTroll::class)->evaluate(
    new Context($trustedInstructions, ['conversation' => $transcript]),
    ['bug' => [Policy::bugReport()]],
    config: new EvaluationConfig(classificationTimeout: 30),
);
```

## Policy thresholds

`Policy` takes an `id`, trusted `criteria`, optional `passThreshold` (0.8), `failThreshold` (0.2), and named `references` ([]). Both cutoffs must satisfy `0 <= fail < pass <= 1`.

```php
$policy = Policy::reproductionSteps()->withThresholds(pass: 0.9, fail: 0.15);
```

`withThresholds()` returns a new policy and preserves its references.

Each assessment passes when P(pass) reaches the pass cutoff, or fails when P(fail) reaches 1 − the fail cutoff. Otherwise it is `unknown`. An explicit model choice of `unknown` always abstains. Low P(pass) alone is not a failure: the probability may belong to `unknown`.

These cutoffs apply to **each sample**, not an average or vote. Model probabilities are not calibrated guarantees; tune on labeled development data and check held-out cases before adopting changes.

## Optional paraphrasing

Set `variants` above zero to assess rewrites as well as the original. For example, `new EvaluationConfig(variants: 3)` adds three rewrites using the configured text providers.

Every sample must pass for a policy to pass. A failing guard sample vetoes action; disagreement or uncertainty withholds action. A failed or malformed rewrite blocks the entire evaluation. Paraphrasing does not sanitize the original, and can change meaning or add cost without improving decisions. Benchmark it before enabling it.

## Inspect results

| API | Meaning |
| --- | --- |
| `$result->allows('bug')` | All guards and policies in that branch passed. Unknown branch names throw. |
| `$result->guarded()` | Guards passed and there were no rewrite errors. |
| `$result->needsReview()` | A guard blocked the run, a rewrite failed, or a branch contains `unknown` or `error`. |
| `$result->actionKey('bug')` | Stable key for that evaluation/branch. A fresh evaluation gets a new key. |
| `$result->guards`, `$result->branches` | Policy results, each with its policy, verdict, and assessments. |
| `$result->classificationCalls` | Request-level provider/model and token usage. |

Verdicts are `pass`, `fail`, `unknown`, `error`, and `skipped`. A branch policy fails when all its samples fail; mixed pass/fail judgments become unknown. Invalid judgments or provider failures produce errors. Policies after a non-pass are skipped. Independent branches can still pass.

Assessments include candidate probabilities, optional confidence, and a `score` of P(pass), or null for an explicit unknown. They do **not** include written reasons or missing-information lists. Request-level usage may include questions for policies whose results were later skipped; skipped does not promise zero inference cost.

For diagnostics, `assess($context, $policy)` judges one policy and `paraphrase($context)` generates one rewrite. Both accept explicit provider/model/timeout arguments and use SDK defaults otherwise, not bridge overrides. Neither runs the full guard pipeline or authorizes work.

`dispatch($result, $actions)` returns the dispatched branch names. Factories run synchronously for allowed branches and should only construct jobs. Persist evaluations privately, dispatch after committing application state, and use an outbox if durable delivery is required. Never trust caller-supplied evaluations as authorization; reassess and reauthorize when context, instructions, or permissions change.

## Custom policy objects

Custom policy classes implement `Vos\TollTroll\Contracts\Policy`, as in the [getting-started example](../README.md#custom-policies). They can also accept domain references through their constructor:

```php
use Vos\TollTroll\Contracts\Policy as PolicyContract;

final readonly class DocumentedBehavior implements PolicyContract
{
    public function __construct(private array $documents) {}

    public function id(): string { return 'documented_behavior'; }
    public function criteria(): string { return 'Pass when reported behavior contradicts the documented contract.'; }
    public function passThreshold(): float { return 0.85; }
    public function failThreshold(): float { return 0.15; }
    public function references(): array { return $this->documents; }
}
```

Return resolved, nonempty UTF-8 reference text keyed by nonempty names. The same contract works for bridges, direct evaluation, annotations, and benchmark suites.
