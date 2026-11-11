🧌 If users wanna cross the bridge, they gotta pass the TollTroll.

<h1><picture>
  <source media="(prefers-color-scheme: dark)" srcset="resources/images/tolltroll-wordmark-dark.svg">
  <img src="resources/images/tolltroll-wordmark.svg" alt="TollTroll" width="320">
</picture></h1>

A Laravel policy gate for deciding whether agent workflows should proceed, stop, or ask a human. Evaluate context against layered policies before dispatching Laravel jobs, then benchmark those policies against your own ground truth. Uses `laravel/ai` structured-output agents without selecting an inference vendor.

Paraphrasing is an optional experiment, not a safety guarantee. Injection screening is one additional check; tool authorization and human approval remain application responsibilities.

**Unreleased.** Package name: `vos/toll-troll`. Requires PHP 8.3+, Laravel 12 or 13, and Laravel AI SDK 1.3+. Composer resolves the framework patch requirements of the SDK. Install this checkout through a Composer path repository while developing; it is not published to Packagist.

## 🧌 Evaluate, then dispatch

```php
use Vos\TollTroll\Context;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;

$tollTroll = app(TollTroll::class);

$result = $tollTroll->evaluate(
    new Context(
        instructions: (string) $implementationAgent->instructions(), // Trusted application configuration.
        sources: [
            'support-conversation' => $transcript,
            'application-log' => $log,
        ],
    ),
    branches: [
        'bug' => [
            Policy::bugReport(),
            Policy::expectedAndActualBehavior(),
            Policy::reproductionSteps(),
        ],
        'feature' => [Policy::featureRequest()],
    ],
);

$tollTroll->dispatch($result, [
    'bug' => fn (Evaluation $evaluation) => new StartFixAgent($evaluation),
    'feature' => fn (Evaluation $evaluation) => new NotifyProductTeam($evaluation),
]);
```

The job classes above belong to your application and must implement Laravel's `ShouldQueue`. Factories should only construct jobs; they run synchronously, only for allowed branches. Laravel's configured queue connection controls execution (the `sync` connection executes immediately). `evaluate()` makes synchronous inference calls; invoke it from an application job for background processing. There are no package migrations or conversation tables required for evaluation itself.

## 🧌 Decision rules

1. Evaluate the original context by default. Opt into additional paraphrases with `variants` (for example, `3`). Every rewrite must retain all source identifiers. The original remains unchanged.
2. Evaluate the original and each paraphrase separately. Every configured classifier scores whether each policy is satisfied; PHP applies the policy's thresholds to derive a verdict. Other samples, previous verdicts, and threshold values are not included in classifier prompts.
3. The mandatory `noPromptInjection()` guard runs first. Any `fail` vetoes action; errors or uncertainty also withhold action. Optional `guards: [new Policy(...)]` run sequentially after it with the same veto behavior.
4. Independent branches run only after all guards pass. A branch advances only when all judgments on its current policy pass. All-fail means `fail`; disagreement or an `unknown` means `unknown`; an execution/validation error means `error`. Remaining dependent policies are `skipped`.
5. Dispatch only the jobs mapped to branches whose policies all passed. A message can satisfy both bug and feature branches.

For guards, a detected violation takes precedence over an execution error in the aggregate verdict; individual errors remain in the assessments. A missing or malformed paraphrase blocks the entire evaluation rather than silently reducing the sample count.

Results contain the exact original context, paraphrases, policy criteria, thresholds and references, individual scores and verdicts, provider/model metadata, quoted evidence, and missing information. Evidence quotes are checked against the identified source **in that sample**, or the unchanged reference text. This checks provenance, not the truth of the model's interpretation. Error records retain exception classes, not potentially sensitive exception messages. Invalid application configuration throws rather than producing a model decision.

`$result->allows('bug')` checks both guards and that branch. Unknown branch names throw. `$result->needsReview()` reports failed guards or uncertain/errored decisions. A unanimous negative business classification is not an automatic human escalation; inspect the branch's `missing` fields to request clarification.

## 🧌 Policy thresholds

Every score measures **policy satisfaction**, from 0 (clearly not satisfied) to 1 (clearly satisfied). For `noPromptInjection()`, higher means the context appears free of injection, not more suspicious. The model supplies a score; TollTroll determines the verdict:

- Score ≥ `passThreshold` → `pass`.
- Score ≤ `failThreshold` → `fail`.
- Between thresholds, or an explicit `null` abstention → `unknown`.
- Missing, nonnumeric, or out-of-range scores → `error`, never a pass.

Defaults are pass at 0.8 and fail at 0.2. These are **uncalibrated starting values**, not measured safety guarantees. Thresholds must satisfy `0 <= fail < pass <= 1`; invalid values throw before inference.

```php
Policy::bugReport()->withThresholds(pass: 0.85, fail: 0.15);
Policy::reproductionSteps()->withThresholds(pass: 0.95, fail: 0.3);

new Policy(
    'high_value',
    $yourTrustedValueRubric,
    passThreshold: 0.9,
    failThreshold: 0.25,
);
```

`withThresholds()` returns a new immutable policy. The automatic injection guard uses `injection_guard` in `config/toll-troll.php`:

```php
'injection_guard' => [
    'pass' => 0.98,
    'fail' => 0.15,
],
```

These thresholds apply **per sample and per classifier**, not to averages or vote percentages. All samples must still pass before an action is allowed. Raising the pass threshold makes passage stricter; raising the fail threshold classifies more scores as definite failures rather than unknowns. Both failed and unknown guards block actions.

Read each assessment's `score` to compare original and paraphrased inputs; an abstention or error has no score, so check `verdict` too. Scores are model estimates, not calibrated probabilities. Tune thresholds on labeled benign and malicious examples, evaluate on held-out data, and recheck when changing models or criteria. Do not treat agreement or a score of 1 as proof of safety. Existing classifier fakes must now supply `score` instead of `verdict`.

## Custom policies and providers

```php
new Policy('low_effort', $yourTrustedEffortRubric);
new Policy('high_value', $yourTrustedValueRubric);
```

Pass criteria from application configuration, never from the context being classified. Effort/value need application-specific rubrics and sufficient context. The supplied reproduction policy checks whether there is enough information to **attempt** reproduction, not whether reproduction or a fix succeeded.

### 🧌 Domain references

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

`references` is an `array<string, string>` of resolved UTF-8 text, defaulting to `[]`. Each name and text must be nonempty; nulls, callbacks, and invalid UTF-8 throw rather than silently dropping context. API calls and vector searches work equally well: perform them in your application and pass their text results. Choose authorized paths and documents, handle retrieval failures, and redact secrets before constructing the policy. TollTroll does not retrieve or truncate content. Apply input limits before `evaluate()`; the benchmark command separately enforces its byte limit.

References stay unchanged across original, repeated, and paraphrased assessments, and `withThresholds()` preserves them. They enter the classifier as data separate from report sources, never as trusted instructions, and do not enter paraphraser prompts. The mandatory injection guard screens references from all branches and custom guards using qualified reference names. Business policies receive only their own references. This adds input tokens, not extra inference calls.

The classifier is instructed not to treat documentation as facts supplied by the reporter. A report-completeness policy must not acquire missing reproduction steps from a manual. If references may supply a fact, say so in the policy, as in the documented-contract example. This distinction is model behavior, not something quote validation can guarantee.

Evidence contains `kind: 'source'|'reference'`, `source` (the name within that collection), and `quote`. An omitted `kind` still means `source` for existing fakes and captures. Sources and references may share names without making their citations interchangeable.

Resolved references are included in evaluation and annotation snapshots and benchmark capture identity. Changed names or text require new inference and review of affected ground truth. Older reference-free annotations and captures remain supported. The array remains resolved strings; future deferred retrieval can resolve outside `Policy` without changing that contract.

### Providers

Publish configuration with `php artisan vendor:publish --tag=toll-troll-config`. Each entry in `paraphrasers` or `classifiers` has `provider` and `model` keys. Null uses the Laravel AI SDK defaults from `config/ai.php`. Provider names may reference any configured SDK text provider capable of the required structured output, including local providers. Configure credentials in the SDK, not this package.

`Agents\Classifier` is a Laravel AI SDK `Agent` with `HasStructuredOutput`, not a separate inference client. The SDK's dedicated `Classification::of()` API currently supports fewer providers and returns typed probabilities rather than TollTroll's reasons, missing information, and evidence quotes. The structured agent keeps those diagnostics available across SDK text providers; its estimated satisfaction score is not a calibrated probability.

Paraphrases are distributed round-robin across `paraphrasers`; the total is `variants`, not that count per model. Every `classifiers` entry votes on every sample. These lists are ensembles, not failover lists. Timeout is per inference call. All calls are currently sequential. With the default zero variants and one classifier, an evaluation visiting five policies makes five calls. Opting into three variants makes 3 + (4 × 5) = 23 calls. Skipped policies make no calls. With zero variants, `paraphrasers` may be empty. Set application-level input limits, timeouts, rate limits, and cost budgets accordingly.

## 🧌 Benchmark your policies

`php artisan toll-troll:benchmark` collects isolated policy judgments against labeled JSONL records. Compare models, original/repeated/paraphrased sample weights, and pass/fail thresholds. Replay saved judgments to change weights and thresholds without further inference calls. Reports retain errors and abstentions instead of excluding difficult records, and include incorrect passes, withheld passes, confusion tables, latency, and SDK token usage.

Weighted scoring is **benchmark-only**: it never changes the production gate, dispatches jobs, or emits review events. See [benchmarking](docs/benchmarking.md) for the dataset format, a runnable suite, limits, and interpretation. The low-level `assess()` and `paraphrase()` methods support diagnostics; unlike `evaluate()`, they do not apply the mandatory guard or authorize any action.

## 🧌 Optional human annotation

The same Composer package includes an opt-in review UI and `AnnotationRequested` event listener. It captures application-selected context and policy snapshots, records human labels, and exports reviewed JSONL for benchmarking. No annotation routes, listeners, or migrations load with the default provider. Enable `AnnotationServiceProvider` explicitly and configure dataset authorization. See [annotations](docs/annotations.md).

## Actions, review, and persistence

`dispatch()` returns the dispatched branch names. When `needsReview()` is true it emits `Vos\TollTroll\Events\ReviewRequired`, even if no action mappings were supplied. No task job is dispatched past a failed guard. If only one business branch is uncertain, other passing branches can still dispatch. Calling `evaluate()` alone never dispatches jobs or review events.

Use Laravel listeners, notifications, and application jobs to persist evaluations or contact a reviewer. The package does not assume a session model, notification channel, reviewer, or agent runtime. A review event withholds work; it does not suspend an already-running agent or automatically resume anything. See [examples](examples/README.md) for triage, notifications, and the SDK's actual human tool approval mechanism.

Dispatch is **not exactly-once or transactional**. Persist an evaluation once, reuse its `actionKey($branch)` for application-level idempotency, and dispatch after committing application state. Retries can duplicate jobs/events; a queue failure can occur after another job was queued. Implement idempotency at the side effect, and use an outbox if delivery must be durable. A fresh evaluation gets a fresh ID. `context->fingerprint()` identifies the exact context, including trusted instructions, but does not include policies or model configuration.

Keep stored evaluations private: they contain the original sensitive context and generated text. Do not accept serialized `Evaluation` objects or approval decisions from an untrusted caller as authorization. They are application data, not signed attestations. Use the evaluated snapshot when dispatching; if context, instructions, or permissions change, reassess and reauthorize. Evaluate separately for targets with different instruction scopes.

## 🧌 Security expectations

Paraphrasing can expose instruction attempts hidden by carefully crafted wording, but it can also lose meaning, introduce errors, or add cost without improving decisions. A single detected guard violation blocks work even when other samples pass. The paraphraser is instructed to preserve instruction attempts instead of sanitizing them away. Benchmark this strategy against single and repeated classification on your own held-out cases before enabling it.

This is defense in depth, not an injection-free guarantee. Correlated models can all be wrong; paraphrases can lose or invent meaning, and source-ID validation cannot detect that. Repeated probing and adaptive attacks may still succeed. No numerical confidence is inferred from agreement. Models have no tools during evaluation. Their output and the original context remain untrusted downstream: never promote them to system instructions. Enforce tool authorization, least privilege, parameter validation, and human approval for consequential actions outside the classifier. Keep detailed verdicts internal rather than exposing a probing oracle.

The unit/integration tests use controlled inference responses. They establish orchestration and fail-closed behavior, not injection detection rates. Before production, evaluate your chosen models against labeled benign/injection examples, scope-sensitive requests, quoted attacks, incomplete reports, multilingual/encoded inputs, and repeated adaptive attempts. Measure false positives and missed attacks as well as cost and latency.

## 🧌 Laravel Boost

The package includes consumer guidelines in `resources/boost/guidelines/core.blade.php`, covering policy gates, opt-in annotations, and safe benchmark collection/replay. In an application using Laravel Boost, run `php artisan boost:install` to discover them, or `php artisan boost:update --discover` to discover newly installed packages. Boost is optional and is not a runtime dependency.

## Development

`composer install` installs dependencies. `composer check` validates the manifest, checks Laravel Pint formatting, and runs PHPUnit/Testbench. `composer format` applies formatting. `.agents/setup` installs PHP 8.3 and Composer in a Debian orb when missing, then installs dependencies.
