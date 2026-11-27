# Human annotation

Use the optional dashboard to check how your policies handle real cases. You can review saved model predictions or label cases without seeing a prediction, then export the results for [benchmarks](benchmarking.md). Labels never authorize jobs or resume agents.

## Enable in a Laravel application

Add this provider to `bootstrap/providers.php`:

```php
Vos\TollTroll\Annotations\AnnotationServiceProvider::class,
```

Use a valid application encryption key. Full Laravel applications already include the required database, encryption, routing, and Blade components.

Using MongoDB? Complete the [MongoDB connection setup](#mongodb) first. Then publish and apply the migrations through your normal deployment process:

```sh
php artisan vendor:publish --tag=toll-troll-annotations-migrations
php artisan migrate
php artisan vendor:publish --tag=toll-troll-annotations-config
```

Define who may review each bridge in your application provider:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('review-toll-troll-annotations', function ($user, string $dataset): bool {
    return $user->canReviewAnnotationDataset($dataset); // Implement your access rule.
});
```

Once you've captured a case, open `/toll-troll/annotations` while signed in to your application. You'll see the bridges you have access to; use **Switch bridge** to change queues. Authentication and this Gate are required for viewing, labeling, exporting, and dismissing advice. Until you define the Gate, nobody can review cases.

The API parameter `dataset` means **bridge name**. Use distinct names per entrypoint, and per tenant when applicable; authorize access accordingly. Names accept 1–64 letters, digits, underscores, or hyphens.

### MongoDB

The annotation dashboard supports MongoDB as well as SQL. To use it:

1. Install the optional Laravel integration:

   ```sh
   composer require mongodb/laravel-mongodb:^5.12
   ```

2. Follow [MongoDB's Laravel connection guide](https://www.mongodb.com/docs/drivers/php/laravel-mongodb/current/connect/connect-to-mongodb/) and select that connection as your application's **default database connection**. Use a replica set or sharded cluster; a standalone server cannot run the transactions needed for capture, reviews, and cleanup.
3. Publish and run the migrations using the commands above. The same publish tag selects the MongoDB collection/index migration automatically. Don't run the SQL annotation migrations against MongoDB.

Capture events, authorization, encryption, review URLs, advice, and JSONL exports work the same way. Switching connections does **not** move existing SQL annotations into MongoDB; any data migration is application-owned. SQL users don't need the MongoDB package.

For your own queries, use `app(Annotation::class)` and `app(AnnotationAnalysis::class)` to select the right models for either backend; see the [cleanup examples](#account-deletion). Static calls such as `Annotation::where(...)` use the SQL model unless you import `Vos\TollTroll\Annotations\MongoDB\Annotation` (or `AnnotationAnalysis`) explicitly. Use model queries for deletion: raw collection deletes bypass review-history and advice-dismissal cleanup.

## Capture predictions from a run

After evaluating, explicitly request review:

```php
use Vos\TollTroll\Annotations\EvaluationReviewRequested;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;

$bridge = new Bridge('support-webhook', $trustedInstructions, [
    'bug' => [Policy::bugReport(), Policy::reproductionSteps()],
]);

$evaluation = app(TollTroll::class)->cross($bridge, ['conversation' => $redactedTranscript]);
event(new EvaluationReviewRequested($bridge, $ticketKey, $evaluation));

// Omit TollTroll::dispatch() to observe decisions without triggering actions.
```

Capture stores one case per guard/branch policy, including skipped policies. Reviewers see the exact context, policy, branch, and saved judgments. Capture makes no additional inference calls, queues no jobs, and sends no review notifications. `cross()` itself calls your inference provider.

Use a stable application key (at most 255 bytes), not a credential or personal identifier. Re-submitting the same evaluation/key is idempotent and preserves labels. A fresh evaluation creates new cases, even for identical input. An evaluation cannot be captured under a different bridge name.

Redact **before** evaluating so retained text matches what was judged. Capture passing and blocked runs to avoid hiding mistakes. See [Troll Patrol](../examples/README.md#-troll-patrol) for an observation-only example.

## Capture blind cases

If you'd rather label cases without seeing the model's answer, use `AnnotationRequested`:

```php
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Context;
use Vos\TollTroll\Policy;

event(new AnnotationRequested(
    dataset: 'support-webhook',
    key: $ticketKey,
    context: new Context($trustedInstructions, ['conversation' => $redactedTranscript]),
    policy: Policy::reproductionSteps(),
));
```

This stores a snapshot without inference. Identical bridge/key/context/policy submissions preserve the existing case and label; changed context or policy creates a new pending case.

Both capture events are synchronous database writes. Dispatch after your application's transaction commits if a rollback should cancel capture. Handle delivery failures in your application; TollTroll does not automatically capture every evaluation.

## Review and export

Choose the **expected policy outcome**, not whether the model was correct:

- `pass`: the context satisfies the policy.
- `fail`: it violates the policy. A correct predicted failure should be labeled `fail`.
- `unknown`: there is insufficient information to decide. This is a reviewed label, not a pending case.

Add an optional note and save explicitly. The queue shows pending, annotated, agreement, disagreement, or non-comparable status. Errors and skipped policies can be labeled but have no comparable prediction. Skipped policies are not run retroactively. Saving preserves the original prediction and records review history; stale forms receive HTTP 409 rather than overwriting another review.

Export reviewed cases as JSONL, one bridge and exact policy revision at a time. Pending cases are excluded. Exports include context, references, notes, human `expected` labels, and any historical `prediction` snapshot. They are plaintext: store them privately and keep them out of source control.

Use exports as input for **fresh** benchmark runs, not `--replay`. The benchmark requires matching policy IDs, criteria, and references. Deduplicate related sources before splitting tuning and held-out data. Prediction-linked review can bias labels; use blind capture for independent labeling.

### Keyboard shortcuts

Available on desktop layouts at least 761px wide with a primary mouse or trackpad:

| Action | Left hand | Right hand |
| --- | --- | --- |
| Select Pass | A | J |
| Select Fail | S | K |
| Select Unknown | D | L |
| Previous case | Q | U |
| Next case | E | O |
| Save | Ctrl/Cmd + Enter | Ctrl/Cmd + Enter |

Selecting a label does not save or advance. Navigation follows the pending-first queue without wrapping. Unsaved edits trigger a leave-page warning. Letter shortcuts ignore typing fields; Ctrl/Cmd + Enter also works in the note field. With multiple advice cards, J/K browse advice outside the review form instead. Buttons and links work on touch devices and without JavaScript.

## Annotation advice

Opt in with `analysis.enabled => true` in `config/toll-troll-annotations.php`, then run:

```sh
php artisan toll-troll:analyze-annotations
php artisan toll-troll:analyze-annotations support-webhook
```

Analysis sends reviewed context, references, notes, and predictions to a **text-generation** provider. It uses Laravel AI's text defaults, independently of classification; override `analysis.provider` and `analysis.model` if needed. The timeout is `toll-troll.timeout`. Approve the provider's data handling before enabling it.

By default, each exact policy revision needs at least 30 distinct reviewed contexts, five disagreements, and a 10% disagreement rate within the latest 100 eligible contexts. Repeated contexts count once; conflicting labels, blind cases, errors, and skipped predictions are excluded. These are configurable heuristics, not accuracy guarantees.

Advice appears on the dashboard with supporting cases, a proposed experiment, and a validation plan. It may suggest thresholds, wording, references, input improvements, or classifier comparisons. Analysis can return no advice. Page loads and labeling never call a provider, and suggestions never change configuration automatically.

Use the card's ground-truth download and [benchmarking walkthrough](benchmarking.md#benchmark-an-advice-suggestion) before adopting a suggestion. Cases used by the advisor are tuning data, not an independent evaluation set. Changing a bridge's classifier affects its guards too; test those before adoption.

Arrows or J/K browse multiple suggestions. The × button dismisses a suggestion only for the signed-in reviewer. Review edits hide stale advice; rerun analysis to refresh it. Unchanged completed analyses are cached. The package does not schedule analysis; if desired, add a task to your application's scheduler:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('toll-troll:analyze-annotations')->daily()->withoutOverlapping();
```

### Retiring advice when a policy changes

Capturing a new policy revision retires older advice for that bridge/policy ID. Wording, reference, and threshold changes count as revisions. Keep stable IDs for the same policy and separate IDs for independent policies.

If captures may arrive out of deployment order, explicitly announce the active policy when it ships:

```php
use Vos\TollTroll\Annotations\PolicyDeployed;

event(new PolicyDeployed('support-webhook', $deployedPolicy));
```

Analysis must be enabled. After the first explicit deployment, only further deployment events change the active revision for that bridge/policy ID. Send the event for rollbacks too, and for every affected bridge. Retiring advice hides it; it does not delete stored summaries.

## Settings

All settings below live in `config/toll-troll-annotations.php`:

| Key | Default | Purpose |
| --- | --- | --- |
| `path` | `'toll-troll/annotations'` | Dashboard URL prefix. |
| `middleware` | `['web']` | Preserve sessions and CSRF protection. Add application authentication middleware for login redirects. |
| `retention_days` | `null` | Optional capture-age limit; requires scheduling pruning separately. |
| `analysis.enabled` | `false` | Enable command-driven advice. |
| `analysis.provider` / `analysis.model` | `null` / `null` | Text provider/model; null uses SDK defaults. |
| `analysis.min_reviewed` | `30` | Minimum distinct comparable reviewed contexts (at least 3). |
| `analysis.min_disagreements` | `5` | Minimum disagreements (at least 3). |
| `analysis.min_disagreement_rate` | `0.1` | Required disagreement fraction, greater than 0 and at most 1. |
| `analysis.max_cases` | `100` | Latest eligible distinct contexts to send; at least `min_reviewed`. This limits cases, not tokens. |

## Privacy and retention

Context, policies, predictions, notes, and advice are encrypted with Laravel's application key. Bridge names, application keys, labels, timestamps, and reviewer IDs are plaintext; keep secrets out of identifying fields. Protect exports and backups and retain keys needed to decrypt stored data. Disabling the provider or analysis does not delete records.

### Optional age-based pruning

Set `retention_days` to a positive PHP integer, for example `30`. Null disables pruning; zero and uncast environment strings are invalid. Preview before scheduling deletion:

```sh
php artisan model:prune --model='Vos\TollTroll\Annotations\Annotation' --pretend
```

For MongoDB, use its model instead:

```sh
php artisan model:prune --model='Vos\TollTroll\Annotations\MongoDB\Annotation' --pretend
```

After approving the retention policy, schedule it in your application's `routes/console.php`:

```php
use Illuminate\Support\Facades\Schedule;
use Vos\TollTroll\Annotations\Annotation;

Schedule::command('model:prune', ['--model' => [app(Annotation::class)::class]])->daily();
```

Resolving the model through `app()` selects SQL or MongoDB for the schedule. Pass it explicitly; Laravel's default model discovery does not scan this package.

Pruning permanently deletes snapshots across **all bridges** by capture age, whether pending or reviewed. Reviewing does not extend retention. Deletion is bulk-based without individual model hooks. SQL needs foreign keys enabled to cascade review-history deletion; the MongoDB model handles that cleanup in a transaction.

### Account deletion

Your application must map accounts to bridge names and sample keys. Resolve that mapping before removing ownership records, then delete the matching snapshots for each bridge:

```php
use Vos\TollTroll\Annotations\Annotation;

app(Annotation::class)->where('dataset', $bridgeName)
    ->whereIn('sample_key', $annotationKeysForAccount)
    ->delete();
```

`reviewer_id` identifies the reviewer, not the source-data owner. Stop future captures for deleted accounts; another capture can recreate a snapshot.

Pruning and case deletion do **not** remove advice summaries. Delete analyses for each affected bridge separately:

```php
use Vos\TollTroll\Annotations\AnnotationAnalysis;

app(AnnotationAnalysis::class)->where('dataset', $bridgeName)->delete();
```

Deleting analyses also deletes their dismissals, through foreign keys on SQL or transactional model cleanup on MongoDB. On whole-bridge/account deletion, explicitly remove matching rows from `toll_troll_annotation_policy_revisions` too; preserve that revision ledger during ordinary pruning so old advice stays retired. Cover downloaded exports, benchmark reports, and backups in your application's cleanup process.

## Upgrading an existing installation

Republish package migrations and apply any new ones before capturing cases after an upgrade. On SQL, prediction capture requires `add_prediction_to_toll_troll_annotations`; advice also requires `create_toll_troll_annotation_analyses` and `create_toll_troll_advice_state`. MongoDB uses its own collection/index migration, selected by the default connection when publishing. Existing labels remain valid. Existing `dataset` values are used as bridge names without renaming data or URLs.
