🧌 If users wanna cross the bridge, they gotta pass the TollTroll.

<h1><picture>
  <source media="(prefers-color-scheme: dark)" srcset="resources/images/tolltroll-wordmark-dark.svg">
  <img src="resources/images/tolltroll-wordmark.svg" alt="TollTroll" width="320">
</picture></h1>

A Laravel policy gate for deciding whether agent workflows should proceed, stop, or ask a human. Evaluate context against layered policies before dispatching Laravel jobs, then benchmark those policies against your own ground truth. Uses the `laravel/ai` classification API without selecting an inference vendor.

Put a troll between incoming context and the work it might trigger:

- **Support triage:** check whether a bug report describes expected behavior, actual behavior, and reproduction steps before queueing an implementation agent.
- **Product feedback:** identify feature requests, apply your own effort/value rubric, and notify the product team when a request qualifies.
- **Human review:** route uncertain cases to an application-owned review workflow instead of starting an agent with incomplete information.
- **Policy tuning:** label real conversations and compare models, paraphrases, weights, and thresholds against that ground truth before changing your workflow.

## Installation

Requires PHP 8.3+, Laravel 12 or 13, and Laravel AI SDK 1.4.1+. Composer resolves the SDK's framework patch requirements.

```sh
composer require vos/toll-troll
```

Laravel discovers the service provider automatically. Configure your inference providers and credentials through the Laravel AI SDK's `config/ai.php`; TollTroll uses the SDK defaults unless you override them. Evaluation needs no migrations or conversation tables. The annotation dashboard is a separate, opt-in setup within the same package.

## 🧌 Basic usage

Define a **Bridge** for each input entrypoint: its trusted target instructions, policy branches, guards, and optional evaluation configuration. Send incoming source text across that bridge:

```php
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;

$tollTroll = app(TollTroll::class);

$bridge = new Bridge(
    name: 'support-webhook',
    instructions: (string) $implementationAgent->instructions(), // Trusted application configuration.
    branches: [
        'bug' => [
            Policy::bugReport(),
            Policy::expectedAndActualBehavior(),
            Policy::reproductionSteps(),
        ],
        'feature' => [Policy::featureRequest()],
    ],
);

$result = $tollTroll->cross($bridge, [
    'support-conversation' => $transcript,
    'application-log' => $log,
]);

$tollTroll->dispatch($result, [
    'bug' => fn (Evaluation $evaluation) => new StartFixAgent($evaluation),
    'feature' => fn (Evaluation $evaluation) => new NotifyProductTeam($evaluation),
]);
```

The injection guard runs before branch policies by default. Each branch advances only when its current policy passes; independent branches can both qualify. `dispatch()` queues only the jobs mapped to allowed branches. The jobs above belong to your application and must implement Laravel's `ShouldQueue`.

Call `$result->allows('bug')` to check the guard and branch together, or `$result->needsReview()` to check for failed guards or uncertain/errored decisions. `cross()` itself never dispatches jobs or review events. Inference is synchronous; run it inside an application job for background processing.

Give different entrypoints different bridge names, even when every rule is identical: `support-webhook` and `feedback-form` keep separate annotation queues, labels, and exports. Reuse a bridge definition for successive inputs at the same entrypoint. Names are stable application-owned identifiers (1–64 letters, digits, underscores, or hyphens), never derived from policy hashes or untrusted input. The evaluation retains the name as `$result->bridge`.

Pass `guards:` and `config: new EvaluationConfig(...)` on `Bridge` to customize its rules. Existing `evaluate($context, $branches, guards: ..., config: ...)` calls remain supported as unnamed evaluations; use `cross()` for entrypoint identity. Existing annotation datasets appear as bridges without a database migration. See [annotations](docs/annotations.md) for capture and switching.

## 🧌 Features

| Feature | What it gives you |
| --- | --- |
| Named bridges | Define a gate per input entrypoint and review its evaluations independently, even when entrypoints share policies. |
| Layered policies | Check conditions in order, such as bug report → expected/actual behavior → reproduction steps. Stop a branch when a prerequisite does not pass. |
| Custom policies and guards | Describe your own acceptance criteria. Add mandatory guards before independent business branches. |
| [Domain references](#-domain-references) | Give the classifier documentation, code, or retrieved knowledge relevant to a policy, separately from the report being judged. |
| Native decisions and automatic batching | Choose one classifier per bridge; policy questions share requests automatically. Compare classifiers in benchmarks and optionally assess paraphrased context. |
| [Policy benchmarks](#benchmark-your-policies) | Compare models and scoring settings against labeled data; replay saved judgments without more inference calls. |
| [Human annotation](#human-annotation) | Capture selected real-world cases, label them in a guarded dashboard, and export ground truth for benchmarks. |
| Laravel jobs and review events | Queue application-owned work for passing branches or notify a reviewer through Laravel listeners and notifications. |
| Laravel Boost guidelines | Give coding agents package-specific guidance for integration, annotations, and benchmark collection/replay. |

### Custom policies

Use application-owned criteria for decisions specific to your domain:

```php
new Policy('low_effort', $yourTrustedEffortRubric);
new Policy('high_value', $yourTrustedValueRubric);
```

Put these in a branch alongside built-in policies. Pass `guards: [new Policy(...)]` to `evaluate()` for additional checks that every branch must pass, after injection screening. Criteria must come from trusted application configuration, never from the context being classified.

Applications can also implement `Vos\TollTroll\Contracts\Policy` with their own value objects:

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

Use these objects in branches, explicit guards, `assess()`, annotation events, or a benchmark suite's `policy`. TollTroll copies the five contract values into a validated immutable `Policy` snapshot before use. Results, queued jobs, and stored annotations retain that snapshot, not your custom class or its extra fields. Return stable, resolved data; resolve files, APIs, or vector searches in the application before passing the policy. Existing constructors, public properties, and built-in factories remain supported. The method-based contract works on PHP 8.3 without requiring PHP 8.4 property hooks.

### Optional injection screening

Set `injection_guard.enabled` to `false` in `config/toll-troll.php` to omit the automatic injection policy. Explicit `guards:` still run and must pass; with none, branch policies alone control dispatch. Disabling this check also disables its automatic screening of references across policies. `guarded()` then means no configured guard or evaluation error blocked the run, not that injection screening occurred. Old published configurations without `enabled` keep screening on.

### 🧌 Domain references

To help the TollTroll make better decisions, give it additional context for your use case. For example, an API contract can help distinguish a bug from intended behavior, and relevant code can explain what the application currently supports. No need to make the troll guess what lives on the other side of the bridge.

Pass named documentation, code, or other domain knowledge alongside a policy. Resolve the text with Laravel's facades or your own retrieval code:

```php
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Vos\TollTroll\Policy;

$policy = new Policy(
    id: 'documented_behavior',
    criteria: 'Pass when the reported behavior contradicts the documented contract. Use the references to establish the contract; use only the report for observed behavior.',
    references: [
        'draft-contract' => Storage::disk('local')->get('policies/drafts.md'),
        'draft-controller' => File::get(app_path('Http/Controllers/DraftController.php')),
    ],
);
```

For built-in policies, use `withReferences()`:

```php
$policy = Policy::bugReport()->withReferences([
    'product-docs' => Storage::disk('local')->get('docs/product.md'),
]);
```

`withReferences()` returns a new validated policy, preserving its identifier, criteria, and thresholds. It **replaces** the reference set rather than merging it; pass `[]` to clear it. The original policy stays unchanged, and you can chain it with `withThresholds()` in either order.

API calls and vector searches work too: retrieve authorized documents in your application and pass their resolved text. Handle retrieval failures, redact secrets, and limit input size before evaluation; TollTroll does not retrieve or truncate content.

References are data, not instructions or facts supplied by the reporter. If a policy may use them to supply a fact, say so in its criteria, as above. A report-completeness policy must not fill in missing reproduction steps from a manual. The classifier is instructed to make this distinction; a judgment is not a guarantee it interpreted the references correctly.

References stay unchanged across samples and do not enter paraphraser prompts. When enabled, the automatic injection guard screens references from all branches and custom guards; each business policy receives only its own references. They are retained in evaluation, annotation, and benchmark snapshots. Changing reference names or text requires new inference and review of affected ground truth.

### Benchmark your policies

`php artisan toll-troll:benchmark` evaluates isolated policies against labeled JSONL records. Compare models, original/repeated/paraphrased sample weights, and pass/fail thresholds. Save the judgments, then replay them to compare weights and thresholds without further inference calls.

Reports include incorrect passes, withheld passes, confusion tables, latency, and SDK token usage. Errors and abstentions remain in the results. Weighted scoring is **benchmark-only**: it never changes the production gate, dispatches jobs, or emits review events. See [benchmarking](docs/benchmarking.md) for setup, dry runs, data limits, and interpretation.

### Human annotation

Dispatch `EvaluationReviewRequested` with a bridge and completed evaluation to review the predictions actually made for every guard and branch policy. The dashboard keeps model scores/verdicts separate from human expected labels and shows where they agree or disagree. Use `AnnotationRequested` instead for blind labeling without predictions. Both keep annotations separate per bridge, with a bridge switcher, keyboard shortcuts, review history, JSONL exports, and optional retention/account cleanup.

Everything ships in the same Composer package. No annotation routes, listeners, or migrations load with the default provider. Register `AnnotationServiceProvider`, publish its migrations, and define per-bridge authorization to enable it. See [annotations](docs/annotations.md) for setup, capture, access control, and retention.

Try [Troll Patrol](examples/README.md#-troll-patrol) to review live predictions without actions, or [The Two-Bridge Test](examples/README.md#-the-two-bridge-test) to pass an investigation job's results through a fresh policy gate before starting a fix.

Want to click around first? The repository includes a [seeded Laravel demo app](https://github.com/paulinevos/toll-troll/tree/main/example) in `example/`: synthetic annotation queues, predictions, reviewer notes, exports, and fixture-backed tuning advice, with no API keys or live inference. It is local-only and excluded from release archives along with tests and development tooling. Clone the repository to run it; the package's copyable `examples/`, documentation, and runtime assets remain in releases.

## 🧌 Built-in policies

All methods below are on `Vos\TollTroll\Policy`. You can combine them with your own policies.

| Policy | What it checks |
| --- | --- |
| `noPromptInjection()` | Looks for attempts to override the target agent's trusted instructions, impersonate authority, influence evaluation, or induce unauthorized actions/disclosure. Added automatically unless `injection_guard.enabled` is false. A legitimate request within the target agent's scope is not an injection. |
| `bugReport()` | Whether the context reports existing behavior believed to be incorrect. It may also contain a feature request. |
| `expectedAndActualBehavior()` | Whether both expected and observed behavior are explicitly described well enough to compare, without inventing expectations. |
| `reproductionSteps()` | Whether steps or an executable reproduction, inputs, and relevant environment/preconditions are sufficient to **attempt** reproduction. It does not reproduce the bug or prove that it can be fixed. |
| `featureRequest()` | Whether the context requests new or changed functionality. It may also report a bug. |

Effort and value are application-specific, so define their rubrics as custom policies rather than relying on built-in assumptions.

## 🧌 Configuration

Publish the evaluation configuration:

```sh
php artisan vendor:publish --tag=toll-troll-config
```

### Evaluation settings

These application-wide defaults live in `config/toll-troll.php`. A bridge's `EvaluationConfig` can override them without changing shared configuration:

| Key | Default | What it does |
| --- | --- | --- |
| `variants` | `0` | Number of additional paraphrases, not including the original. A nonnegative integer; set `3` to assess three rewrites alongside the original. |
| `injection_guard.enabled` | `true` | Add automatic injection screening before explicit guards and branches. Set false to omit it; explicit guards still run. |
| `injection_guard.pass` | `0.8` | Minimum P(pass) for an individual injection-guard assessment to pass. Higher means the context appears free of injection. |
| `injection_guard.fail` | `0.2` | Failure requires P(fail) ≥ 1 − this value. Together the thresholds must satisfy `0 <= fail < pass <= 1`. |
| `paraphrasers` | `[['provider' => null, 'model' => null]]` | List of engines generating rewrites, selected round-robin. Must be nonempty when `variants > 0`; may be empty otherwise. |
| `paraphrasers.*.provider` | `null` | SDK provider name for that paraphraser. Null uses the SDK's default provider. |
| `paraphrasers.*.model` | `null` | Model for that paraphraser. Null uses the SDK's default model for the selected provider. |
| `classifier` | `['provider' => null, 'model' => null]` | One provider/model pair shared by all guard and branch questions. |
| `classifier.provider` | `null` | SDK provider name using the `typesafe`, `openai`, or `gemini` driver. Null uses `ai.default_for_classification` (the SDK default is `typesafe`), not the text default `ai.default`. |
| `classifier.model` | `null` | Native decision model for that provider. Null uses the SDK's default classification model. |
| `timeout` | `60` | Positive integer timeout in seconds for each inference call, not the entire evaluation. |

Gate decisions use the SDK's native decision APIs with `Choice` candidates `pass`, `fail`, and `unknown`: TypeSafe/Jev, OpenAI Decisions, or Gemini Decisions. Named SDK provider configurations using those drivers work too. Text-generation fallbacks, OpenRouter, and generic OpenAI-compatible drivers are not supported as classifiers. Results retain the selected candidate, candidate probabilities, optional confidence, and request-level usage—not written reasons, evidence quotes, or missing-information explanations. Paraphrasing and annotation advice still use text agents and the SDK's text defaults; configure them independently when necessary.

Paraphrasing is an optional experiment, not a safety guarantee. Injection screening is one additional check; tool authorization and human approval remain application responsibilities.

Paraphrases can expose instruction attempts hidden by carefully crafted wording, but can also lose meaning, introduce errors, or add cost without improving decisions. The paraphraser is instructed to preserve instruction attempts, not sanitize them away. Benchmark original-only, repeated, and paraphrased classification on your own held-out cases before enabling rewrites.

### Automatic batching

TollTroll collects policy questions automatically, including the injection guard and speculative branch questions, then applies guard and branch prerequisites to their answers in PHP. A skipped policy retains no assessments and cannot authorize an action, even if its answer was computed. No answer is fed into another question. If a later step needs newly acquired context or earlier answers, cross a separate bridge with that new input.

Questions sharing exactly the same reference data share a request; different reference sets stay in separate requests so business policies never receive each other's documents. The automatic injection guard still sees all references and may therefore need its own batch. Batches contain at most 400 questions, conservatively accommodating the native providers' limits. There is no public grouping API.

With no references and at most 400 policies, zero variants means **one classification call**, including guards. Three variants means **three rewrite calls plus four classification calls**, not one call per policy. Requests are synchronous and sequential; questions within a native request are evaluated by the provider. Separate reference sets or larger policy lists add batches. Speculative questions can cost money even when logically skipped. Set application-level input limits, rate limits, and cost budgets; SDK retries may add HTTP requests.

`Evaluation::classificationCalls` records each batch's sample, actual provider/model, usage, and any request error once, including batches whose answers are discarded. Batched assessments do not repeat shared token usage; low-level single-policy `assess()` still retains its own request usage.

### Bridge-level overrides

Pass `EvaluationConfig` on `Bridge(config: ...)` or directly to `evaluate()` to customize one evaluation:

```php
use Vos\TollTroll\EvaluationConfig;

$result = $tollTroll->evaluate($context, [
    'bug' => [Policy::bugReport(), Policy::reproductionSteps()],
], config: new EvaluationConfig(
    variants: 0,
    classifier: ['provider' => 'typesafe', 'model' => null],
    classificationTimeout: 30,
));
```

The classifier and timeout apply to **every guard and branch policy** in that bridge. Policy classes define criteria, references, and thresholds, not inference settings. There are no per-policy classifier overrides or configuration attributes. Settings are validated before any inference; the config file and supplied objects are never mutated.

Null config-object fields mean **inherit**. A supplied `classifier` pair replaces the global pair completely; include both `provider` and `model`. Null entries explicitly select SDK defaults. `false` and `0` are explicit values, so `new EvaluationConfig(injectionGuard: false, variants: 0)` disables those features for that run.

Shared paraphrases and screening use `variants`, `paraphrasers`, `paraphraseTimeout`, `injectionGuard`, `injectionPassThreshold`, and `injectionFailThreshold`. These inherit their config-file defaults when omitted; `classificationTimeout` and `paraphraseTimeout` independently inherit the file's `timeout`. Paraphraser lists replace inherited lists, and may be empty only with zero variants.

**Migration:** replace `classifiers: [[...]]` with `classifier: [...]` in published config; replace `classification: new PolicyConfig(...)` with `classifier: [...]` / `classificationTimeout: ...` on `EvaluationConfig`. Remove policy attributes and `policies` overrides. To compare models, use the benchmark's named `models` matrix rather than a production ensemble.

Benchmark suites default to the global classifier and accept explicit `models` / `timeout`; see [benchmark configuration](docs/benchmarking.md#collect-once-compare-many-ways). Low-level `assess()` and `paraphrase()` retain their explicit provider/model/timeout arguments and SDK defaults; they do not apply bridge overrides.

### Policy thresholds

Each `Policy` accepts these constructor arguments. They are configured in your application code, not as global evaluation settings:

| Argument | Default | What it does |
| --- | --- | --- |
| `id` | Required | Nonempty policy identifier. Must be unique within each branch or guard sequence. |
| `criteria` | Required | Nonempty, trusted wording that describes when the policy is satisfied. |
| `passThreshold` | `0.8` | P(pass) at or above this value passes that assessment, unless the chosen candidate is unknown. |
| `failThreshold` | `0.2` | P(fail) at or above 1 − this value fails that assessment, unless the chosen candidate is unknown. Must satisfy `0 <= failThreshold < passThreshold <= 1`. |
| `references` | `[]` | Named domain context as `array<string, string>`. Names/text must be nonempty and valid UTF-8; unresolved callbacks and nulls are rejected. |

```php
Policy::bugReport()->withThresholds(pass: 0.85, fail: 0.15);
Policy::reproductionSteps()->withThresholds(pass: 0.95, fail: 0.3);
```

`withThresholds()` returns a new immutable policy and preserves its references. The automatic injection guard takes its thresholds from `injection_guard` above.

`Assessment::score` is P(pass), from 0 to 1, except an explicit `unknown` choice sets it to `null` and always abstains. `failProbability` is P(fail); both values come from the recorded candidate distribution. With default cutoffs, P(pass) ≥ 0.8 passes and P(fail) ≥ 0.8 fails; otherwise the assessment is unknown. Low P(pass) alone is not failure: probability may belong to `unknown`. Missing or invalid judgments produce `error`. Invalid application configuration throws before inference.

Thresholds apply **per sample**, not to averages or vote percentages. Every assessment must pass for a policy to pass. Raising the pass threshold makes passage stricter; raising the fail threshold lowers the required P(fail). Both unknown and failed guards block action. Model probabilities and confidence are not calibrated guarantees of correctness. Tune on labeled development data, then evaluate on held-out cases when changing models or criteria.

### Annotation settings

After enabling annotations, publish their separate configuration with `php artisan vendor:publish --tag=toll-troll-annotations-config`:

| Key in `config/toll-troll-annotations.php` | Default | What it does |
| --- | --- | --- |
| `path` | `'toll-troll/annotations'` | URL prefix for the annotation dashboard and its review/export routes. |
| `middleware` | `['web']` | Route middleware. Preserve sessions and CSRF protection; add application authentication middleware as needed. Authenticated-user and per-bridge Gate checks are always required as well. |
| `retention_days` | `null` | Optional capture-age limit across all bridges, pending or reviewed. Null disables pruning; a positive PHP integer enables selection by Laravel's `model:prune`. Your application must explicitly schedule it. |
| `analysis.enabled` | `false` | Opt-in, command-driven advice on recurring prediction/label gaps. Uses SDK text defaults unless overridden with `analysis.provider` / `analysis.model`. |
| `analysis.min_reviewed` / `analysis.min_disagreements` / `analysis.min_disagreement_rate` | `30` / `5` / `0.1` | Minimum distinct reviewed contexts and disagreement signal per exact policy revision. Heuristics, not statistical guarantees. |
| `analysis.max_cases` | `100` | Most recently reviewed distinct comparable contexts sent per policy revision. |

Pruning permanently removes matching snapshots and cascades review history with foreign keys enabled, without individual model deletion hooks. Preview with `--pretend` before scheduling. Account ownership and cleanup of exports, reports, and backups remain application concerns; see [retention and account deletion](docs/annotations.md#privacy-and-retention).

Run or schedule `php artisan toll-troll:analyze-annotations` after opting in. Ready advice appears on the annotation dashboard; ordinary page loads and labeling never call a provider. Suggestions are experiments, not automatic changes. Analysis summaries need separate retention/deletion; see [annotation advice](docs/annotations.md#annotation-advice).

Benchmark sample counts, weights, and thresholds belong to an application-owned suite, separate from production configuration. See the [example suite](examples/benchmark.php) and [benchmark settings](docs/benchmarking.md#weights-and-thresholds).

## Decision and action details

### Judgments and uncertainty

Each visited policy assesses the original context alongside any paraphrases. Every rewrite must retain all source identifiers; a missing or malformed rewrite blocks the entire evaluation. The original remains unchanged. Classifier prompts do not contain other samples, previous verdicts, or threshold values.

For guards, any failing assessment vetoes action, even alongside errors. Otherwise errors or uncertainty withhold action. Branch policies pass only when all assessments pass; all-fail means `fail`, disagreement or an unknown means `unknown`, and an execution/validation error means `error`. Dependent policies are `skipped` after a non-pass, discarding any speculative judgments, including errors. Independent branches can still proceed when another branch fails. Missing or invalid typed judgments fail closed for their own question; a transport/SDK decoding failure or unrequested answer invalidates that batch.

Results contain the original context, paraphrases, policy criteria, thresholds and references, selected candidates, candidate probabilities, optional confidence, individual scores and verdicts, and provider/model metadata. No reasons, quotes, or missing-information explanations are requested or stored in new assessments. Error records retain exception classes rather than potentially sensitive messages.

`allows()` rejects unknown branch names. A unanimous negative business classification does not automatically require human review. The application decides how to request clarification from the context and policy; there are no generated missing-information fields. Low-level `assess()` and `paraphrase()` methods are diagnostic APIs without the complete guard pipeline, not authorization APIs.

### Dispatch and persistence

`dispatch()` returns the dispatched branch names and emits `Vos\TollTroll\Events\ReviewRequired` when `needsReview()` is true, even with no action mappings. Factories run synchronously, only for allowed branches, and should only construct jobs. Laravel's queue connection controls execution; `sync` executes immediately.

Use Laravel listeners, notifications, and application jobs to persist evaluations or contact reviewers. A review event withholds work; it does not suspend an already-running agent or automatically resume anything. See [examples](examples/README.md) for triage, notifications, and the SDK's human tool approval mechanism.

Dispatch is **not exactly-once or transactional**. Persist an evaluation, reuse its `actionKey($branch)` for application-level idempotency, and dispatch after committing application state. Retries can duplicate jobs/events, and a queue failure may occur after another job was queued. Implement idempotency at the side effect; use an outbox when delivery must be durable. A fresh evaluation gets a fresh ID. `context->fingerprint()` covers the exact context, including instructions, but not policies or models.

Keep evaluations private: they contain sensitive source and generated text. Do not accept serialized evaluations or approval decisions from untrusted callers as authorization. Reassess and reauthorize if context, instructions, or permissions change; evaluate separately for targets with different instruction scopes.

### Security boundaries

Models have no tools during evaluation, but their output and the original context remain untrusted downstream. Never promote them to system instructions. Enforce tool authorization, least privilege, parameter validation, and human approval for consequential actions outside the classifier. Keep detailed verdicts internal rather than exposing a probing oracle.

Correlated models can all be wrong, source-ID checks cannot detect changed meaning, and adaptive attacks may succeed despite agreement. Tests use controlled inference responses to verify orchestration and fail-closed behavior, not detection rates. Before production, evaluate realistic benign cases, injections, scope-sensitive requests, quoted attacks, incomplete reports, and multilingual/encoded inputs. Measure false positives, missed attacks, cost, and latency.

## 🧌 Laravel Boost

Consumer guidelines in `resources/boost/guidelines/core.blade.php` cover policy gates, annotations, and benchmark collection/replay. In an application using Laravel Boost, run `php artisan boost:install`, or `php artisan boost:update --discover` for newly installed packages. Boost is optional, not a runtime dependency.

## Development

`composer install` installs dependencies. `composer check` validates the manifest, checks Laravel Pint formatting, and runs PHPUnit/Testbench. `composer format` applies formatting. `.agents/setup` installs PHP 8.3 and Composer in a Debian orb when missing, then installs dependencies.
