🧌 If users wanna cross the bridge, they gotta pass the TollTroll.

<h1><picture>
  <source media="(prefers-color-scheme: dark)" srcset="resources/images/tolltroll-wordmark-dark.svg">
  <img src="resources/images/tolltroll-wordmark.svg" alt="TollTroll" width="320">
</picture></h1>

A Laravel policy gate for agent workflows. Check incoming text against your criteria before starting work, and send uncertain cases to a human.

Use it to:

- **Triage reports:** require a bug description and reproduction steps before queueing an investigation.
- **Route requests:** check independent bug and feature-request branches against your own criteria.
- **Review decisions:** label real cases in an optional dashboard and export ground truth.
- **Tune policies:** compare classifiers and thresholds against labeled cases before changing your workflow.

## Installation

Requires PHP 8.3+, Laravel 12 or 13, and Laravel AI SDK 1.4.1+.

```sh
composer require vos/toll-troll
```

Laravel discovers the service provider automatically. Configure providers and credentials in Laravel AI's `config/ai.php`. Classification requires a provider using the `typesafe`, `openai`, or `gemini` driver; TollTroll uses `ai.default_for_classification` unless overridden. Text-generation-only providers cannot classify policies.

## 🧌 Basic usage

A **bridge** describes one input entrypoint: its trusted agent instructions and the policy branches that incoming text must satisfy.

For example, this bridge assesses incoming feature requests with high business value:

- **Low effort:** start an implementation agent and notify the product team.
- **High effort:** notify the product team without starting implementation.

The two custom policy classes are defined [below](#custom-policies).

```php
use App\Jobs\NotifyProductTeam;
use App\Jobs\StartImplementationAgent;
use App\Policies\HighBusinessValue;
use App\Policies\LowEffort;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Evaluation;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;

$troll = app(TollTroll::class);

$bridge = new Bridge(
    name: 'product-feedback',
    instructions: 'Notify the product team about high-value feature requests. Start implementation only for low-effort, high-value feature requests.',
    branches: [
        // Branches are independent: notify ignores effort, so both can pass.
        'notify' => [Policy::featureRequest(), new HighBusinessValue],
        'implement' => [Policy::featureRequest(), new HighBusinessValue, new LowEffort],
    ],
);

$result = $troll->cross($bridge, [
    'conversation' => $transcript,
]);

if ($result->allows('implement')) {
    // The request passed every guard and all three policies.
}

// Optional: queue your application's jobs. If both branches pass, both jobs are queued.
$troll->dispatch($result, [
    'notify' => fn (Evaluation $evaluation) => new NotifyProductTeam($evaluation),
    'implement' => fn (Evaluation $evaluation) => new StartImplementationAgent($evaluation),
]);
```

`$transcript` is your input text. `NotifyProductTeam` and `StartImplementationAgent` are application-owned jobs implementing Laravel's `ShouldQueue`. Notification does not depend on effort; only implementation does. The effort and value criteria are examples; replace them with what matters to your team.

- Automatic **prompt-injection screening** must pass for any branch to proceed.
- Each branch requires all its policies to pass, in order. A non-pass skips dependent policies. You can add independent branches—for example, to route bug reports separately—and more than one branch can pass.
- `cross()` evaluates only. `dispatch()` queues mapped jobs and emits `Vos\TollTroll\Events\ReviewRequired` if guards block the run or a branch is uncertain or errored. An ordinary failed branch alone does not request review.

`instructions` describes the downstream workflow's authorized scope for prompt-injection screening; it is not a task for the classifier to execute. Sources are untrusted text with nonempty names and values. Choose a distinct bridge name for each entrypoint (1–64 letters, digits, underscores, or hyphens).

## Custom policies

Keep each policy in its own class implementing `Vos\TollTroll\Contracts\Policy`. Its `criteria()` method returns **plain text**, not a special rubric schema: describe what should pass or fail, and when there isn't enough information to decide (`unknown`).

Here are the two policies from the example. Save this one as `app/Policies/LowEffort.php`:

```php
<?php

namespace App\Policies;

use Vos\TollTroll\Contracts\Policy;

final readonly class LowEffort implements Policy
{
    public function id(): string { return 'low_effort'; }
    public function passThreshold(): float { return 0.8; }
    public function failThreshold(): float { return 0.2; }
    public function references(): array { return []; }

    public function criteria(): string
    {
        return <<<'TEXT'
            Pass when the requested change is limited to one existing screen or workflow,
            with no database migration or new external integration. Fail when it requires
            those changes or spans multiple workflows. Use unknown if the scope is unclear.
            TEXT;
    }
}
```

And this one as `app/Policies/HighBusinessValue.php`:

```php
<?php

namespace App\Policies;

use Vos\TollTroll\Contracts\Policy;

final readonly class HighBusinessValue implements Policy
{
    public function id(): string { return 'high_value'; }
    public function passThreshold(): float { return 0.8; }
    public function failThreshold(): float { return 0.2; }
    public function references(): array { return []; }

    public function criteria(): string
    {
        return <<<'TEXT'
            Pass when the request describes a recurring blocker affecting multiple paying
            customers. Fail when it describes only a cosmetic preference with no workflow
            impact. Use unknown when the customer impact is unclear.
            TEXT;
    }
}
```

Replace these criteria with what matters to your team. The [configuration guide](docs/configuration.md#policy-thresholds) explains the thresholds; `references()` can return [domain context](#-domain-references) or an empty array.

For a one-off policy, you can also pass an ID and criteria directly to `new Policy(...)`. Add `guards` to the bridge for checks that every branch must pass:

```php
$bridge = new Bridge(
    name: 'product-feedback',
    instructions: 'Notify the product team about high-value feature requests. Start implementation only for low-effort, high-value feature requests.',
    branches: [
        'notify' => [Policy::featureRequest(), new HighBusinessValue],
        'implement' => [Policy::featureRequest(), new HighBusinessValue, new LowEffort],
    ],
    guards: [new Policy('no_personal_data', 'Pass when the text contains no personal contact details. Fail when it includes an email address or phone number.')],
);
```

Policy IDs must be unique within each branch or guard list. Define criteria in trusted application code, never in the input being classified.

### 🧌 Domain references

Give a policy relevant documentation, code, or retrieved knowledge separately from the report:

```php
$policy = new Policy(
    id: 'documented_behavior',
    criteria: 'Pass when the reported behavior contradicts the documented contract. Use the references for the contract and the report for observed behavior.',
    references: ['api-contract' => $resolvedDocumentation],
);

// Works with built-in policies too.
$policy = Policy::bugReport()->withReferences(['product-docs' => $resolvedDocumentation]);
```

Pass resolved text, not paths or callbacks. Retrieve authorized documents, redact secrets, and limit input size in your application. References supply domain knowledge, not missing reporter facts. Automatic injection screening includes references.

`withReferences()` returns a new policy and **replaces** all references; `[]` clears them.

## 🧌 Built-in policies

| `Policy` method | Passes when… |
| --- | --- |
| `noPromptInjection()` | There is no attempt to override trusted instructions, impersonate authority, influence evaluation, or induce unauthorized actions or disclosure. Added automatically by default. |
| `bugReport()` | The context reports existing behavior believed to be incorrect. |
| `expectedAndActualBehavior()` | Expected and observed behavior are both explicitly described well enough to compare. |
| `reproductionSteps()` | Steps or an executable reproduction, inputs, and relevant preconditions are sufficient to attempt reproduction. It does not reproduce the bug. |
| `featureRequest()` | The context requests new or changed functionality. A report can be both a bug and a feature request. |

## 🧌 Configuration

```sh
php artisan vendor:publish --tag=toll-troll-config
```

Defaults: one classifier, automatic injection screening, no paraphrasing, and a 60-second timeout per inference call. Override one bridge without changing global configuration:

```php
use Vos\TollTroll\EvaluationConfig;

$bridge = new Bridge(
    name: 'support-webhook',
    instructions: $trustedInstructions,
    branches: ['bug' => [Policy::bugReport()]],
    config: new EvaluationConfig(
        classifier: ['provider' => 'openai', 'model' => null],
        classificationTimeout: 30,
    ),
);
```

See [configuration and results](docs/configuration.md) for all settings, thresholds, optional paraphrasing, and inspecting decisions. Classifier settings apply to the whole bridge, not individual policies.

## Human annotation

The optional dashboard lets reviewers label the **expected policy outcome** (`pass`, `fail`, or `unknown`), compare it with saved predictions, and export labeled cases. It can also suggest policy-tuning experiments; it never changes your policies automatically.

Use your application's SQL database, or [MongoDB](docs/annotations.md#mongodb) with the optional `mongodb/laravel-mongodb` package.

Start with the [annotation setup guide](docs/annotations.md), or use [Troll Patrol](examples/README.md#-troll-patrol) to collect predictions without dispatching actions.

## Benchmark your policies

Compare models and thresholds against labeled JSONL cases. Save judgments once, then replay them with different scoring profiles without more inference calls.

```sh
php artisan toll-troll:benchmark examples/benchmark.php storage/app/private/cases.jsonl --dry-run
```

Copy/adapt the [example suite](examples/benchmark.php) in your Laravel application first. The [benchmark guide](docs/benchmarking.md) covers collection, replay, and testing annotation advice. Benchmarks judge one policy at a time; they never dispatch application jobs.

## Safe integration

- A passing classification is not a security guarantee. Keep tool authorization, input validation, least privilege, and approval for consequential actions in your application.
- Keep evaluations private: they contain source text, references, and predictions sent to your configured providers. Redact **before** evaluating.
- `dispatch()` is not transactional or exactly-once. Persist and reuse an evaluation's `actionKey($branch)` for idempotency, and deduplicate at the side effect. Laravel's `sync` queue runs jobs immediately.
- `ReviewRequired` is an event for your listeners, not automatic agent pause/resume or human approval. Human labels do not authorize work.

See [application examples](examples/README.md) for review notifications, a two-stage investigation/fix workflow, and Laravel AI human tool approval.

## Try the demo

The repository's [local Laravel demo](example/README.md) includes synthetic review queues, exports, and fixture-backed advice. No API keys or live inference are needed. Clone the repository to run it; the demo is not included in package releases.

## Laravel Boost

In an application using Laravel Boost, run `php artisan boost:install`, or `php artisan boost:update --discover` to discover a newly installed package. Boost is optional.

## Development

Run `composer install`, then `composer check` to validate the manifest, check Pint formatting, and run PHPUnit. `composer format` applies formatting.
