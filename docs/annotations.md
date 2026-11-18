# 🧌 Human ground truth, opt-in

Annotations ship in **the same `vos/toll-troll` package**. Composer has no feature flags that selectively install code. Only `TollTrollServiceProvider` is auto-discovered; annotations remain inactive unless you explicitly register their provider. No annotation tables are created automatically, and ordinary evaluations never store annotation data.

## Bridges and entrypoints

A **Bridge** names the context instructions, policy branches, guards, and evaluation configuration for one input entrypoint. Different entrypoints must use different names even if their rules are identical. The dashboard lists bridges and offers **Switch bridge** in each review queue. Only bridges with captured cases that the reviewer may access appear; queues, labels, navigation, and exports stay separate.

The existing `dataset` column, event parameter/property, route parameter, and Gate ability remain unchanged for compatibility: their value is the **bridge name**. Both capture events accept a `Bridge` object or its name in that parameter. Existing datasets become bridges as-is, preserving URLs, fingerprints, labels, and history; this conceptual change needs no migration. If you previously mixed entrypoints in one dataset, their origins cannot be inferred: use distinct bridge names for future captures and handle any historical split in application-owned data.

## Enable in a Laravel application

Add this provider to `bootstrap/providers.php`:

```php
Vos\TollTroll\Annotations\AnnotationServiceProvider::class,
```

Laravel's full framework already supplies the database, encryption, routing, and Blade components. These are listed as optional Composer suggestions for other integrations. Use a valid application encryption key. Publish the migration and run it against your chosen database through your normal migration approval/deployment process:

```sh
php artisan vendor:publish --tag=toll-troll-annotations-migrations
php artisan migrate
```

Optional configuration: `php artisan vendor:publish --tag=toll-troll-annotations-config`. The default path is `/toll-troll/annotations`. Preserve the `web` middleware for sessions and CSRF protection; add your application's authentication middleware if you want login redirects. The controller independently requires an authenticated user and per-bridge authorization. There is **no permissive default gate**.

Define this ability in your application provider, using your own access rule:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('review-toll-troll-annotations', function ($user, string $dataset): bool {
    return $user->canReviewAnnotationDataset($dataset); // Application-owned permission check.
});
```

The same ability controls viewing, labeling, and export. Index pages and the switcher list only authorized bridge names; every case lookup is scoped to its bridge. For multiple tenants, choose distinct names per tenant and entrypoint and authorize membership; this package does not infer tenancy. Existing application permission methods such as `canReviewAnnotationDataset()` can stay unchanged.

## Submit deliberate snapshots

Dispatch from trusted application code after redacting data you should not retain:

```php
use Illuminate\Support\Facades\Event;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Context;
use Vos\TollTroll\Policy;

Event::dispatch(new AnnotationRequested(
    dataset: 'support-webhook', // Bridge name; the parameter keeps its legacy name.
    key: 'ticket-42', // Stable application key, not a credential or personal identifier.
    context: new Context($trustedTargetInstructions, ['conversation' => $redactedTranscript]),
    policy: Policy::reproductionSteps(),
));
```

The listener is synchronous and stores no model prediction. It performs no inference, starts no agent, and queues no work. The exact same bridge/key/context/policy submission is idempotent; repeating it never resets an existing human label. Changed context or policy creates a fresh pending snapshot. Identical submissions to different bridges remain separate. Bridge names accept 1–64 letters, digits, underscores, or hyphens.

Capture after your application's transaction commits if a rollback should cancel the submission. Handle listener/database failures according to your application's delivery needs; there is no hidden queue or retry policy. The package does not subscribe to all evaluations or production conversations automatically.

## Review predictions from a real run 🧌

Use `EvaluationReviewRequested` when you want human labels tied to predictions that were actually made, rather than collecting unclassified snapshots:

```php
use Illuminate\Support\Facades\Event;
use Vos\TollTroll\Annotations\EvaluationReviewRequested;
use Vos\TollTroll\Bridge;
use Vos\TollTroll\Policy;
use Vos\TollTroll\TollTroll;

$bridge = new Bridge('support-webhook', $trustedTargetInstructions, [
    'bug' => [Policy::bugReport(), Policy::reproductionSteps()],
]);
$evaluation = app(TollTroll::class)->cross($bridge, ['conversation' => $redactedTranscript]);
Event::dispatch(new EvaluationReviewRequested($bridge, $ticketKey, $evaluation));
// Deliberately omit TollTroll::dispatch() while observing predictions without actions.
```

Capture writes one case per guard/branch policy in one database transaction. Each retains the exact context, policy, evaluation ID, branch, aggregate verdict, individual scores/reasons/evidence, provider/model metadata, paraphrases, and evaluation errors, scoped to the bridge name. A named evaluation cannot be submitted under another bridge. Predictions are encrypted at rest. The listener makes no new inference calls and dispatches no work or review notifications; the preceding `cross()` does incur inference costs. Redact data **before** evaluating so the retained context matches what was judged. Legacy unnamed evaluations still accept an explicit bridge name at capture time.

Re-dispatching the same bridge/key/evaluation is idempotent and preserves labels. A fresh evaluation ID creates new cases, even with identical input and predictions. The queue identifies the policy, branch, and run; guards use a null branch. Keep the stable application key for account cleanup. Capture passing and blocked runs alike so reviewers can find both false passes and false failures.

The reviewer selects the **expected policy outcome**, not a yes/no correctness flag. If a predicted fail was correct, label it `fail`; if it should have passed, label it `pass`. The dashboard compares the saved label with the recorded prediction. It never preselects the model's answer or replaces the prediction with the human label. `unknown` is a classification that can agree or disagree; `error` and `skipped` have no comparable prediction, but reviewers can still label the context. Skipped policies are not run retroactively.

Prediction-linked review deliberately shows model output and can bias human labels. Use `AnnotationRequested` for blind labeling when you need an independent held-out set. See [Troll Patrol](../examples/README.md#-troll-patrol) for a complete observation-only example.

**Existing annotation installations:** publish the new `add_prediction_to_toll_troll_annotations` migration and apply it through your normal migration approval process before resuming annotation capture after upgrading, including blind capture. It adds a nullable encrypted payload column; existing annotations and their labels remain valid. Analysis additionally needs the `create_toll_troll_annotation_analyses` migration. Fresh installations publish all package migrations.

## Review and export

The route shows the frozen policy wording, trusted target instructions, escaped source text, and policy references. References are displayed separately as domain context, not facts supplied by the reporter. They are encrypted with the policy snapshot and exported verbatim; changing reference names or text creates a fresh pending snapshot without resetting earlier labels. Older cases without references still work. Reviewers explicitly choose `pass`, `fail`, or `unknown` and optionally add a note. Unknown is a reviewed judgment; a pending case has no label. Model predictions appear only for cases captured through `EvaluationReviewRequested`.

Every save records the reviewer identifier and an append-only revision. Stale forms receive HTTP 409 rather than overwriting another review. Label changes never authorize an agent action. The UI shows the latest label; the `toll_troll_annotation_reviews` table retains history for application audits.

Reviewed cases export as JSONL compatible with [the benchmark command](benchmarking.md), one bridge and exact policy snapshot at a time. Each row includes its `bridge` name so provenance survives downloading. Pending cases are excluded; unknown labels are included. The benchmark refuses exported policy wording or references that differ from its suite. Keep development and held-out annotation sets separate to avoid tuning and evaluating on the same records.

For prediction-linked cases, `expected` is the human label and `prediction` contains the untouched run snapshot. Compare `expected` with `prediction.verdict` to audit those historical decisions without inference. Benchmark collection ignores the old prediction when making fresh judgments; these exports are not benchmark `--replay` captures. Repeated runs can produce multiple cases for the same source, so deduplicate or group by source before splitting training/tuning and held-out sets. The UI comparison is per policy, not a separate human approval of an entire branch or its actions.

## Annotation advice

The Toll Troll can look for recurring, actionable gaps between recorded predictions and human labels. This is **opt-in**: set `analysis.enabled` to `true` in `config/toll-troll-annotations.php` after publishing and applying the analysis migration. It sends reviewed context, policy/reference snapshots, notes, and predictions to an inference provider. Redact sensitive content before capture and approve that provider's data handling first.

Run `php artisan toll-troll:analyze-annotations` to check all datasets, or append a dataset name to check only that dataset. The command is registered only by `AnnotationServiceProvider`. To generate advice automatically, add this to your application's scheduler; the package never schedules inference itself:

```php
Schedule::command('toll-troll:analyze-annotations')->daily()->withoutOverlapping();
```

The advisor uses the **first configured TollTroll classifier's provider and model**, falling back to Laravel AI defaults. It is a separate structured-output agent, not another policy evaluation or an ensemble vote. Override `analysis.provider` and/or `analysis.model` to use a dedicated advisor. An explicit provider with a null model uses that provider's default. The timeout is `toll-troll.timeout`.

Eligibility is evaluated separately for each dataset and **exact policy revision**, including thresholds and references:

- At least **30 distinct reviewed contexts** with a comparable recorded prediction (`pass`, `fail`, or `unknown`). Pending, blind, errored, and skipped cases do not establish a prediction gap and are excluded.
- At least **five disagreements**, representing at least **10%** of the selected contexts. These are configurable heuristics, not statistical significance or a production accuracy estimate. Thirty is a practical starting point for spotting repeated failures, not enough to certify a policy; rare or high-risk failures need larger, representative sets.
- Repeated branches/runs of the same context count once, using the latest reviewed prediction. Identical contexts with conflicting human labels are excluded rather than silently choosing a label. At most the latest **100** distinct contexts are sent (`analysis.max_cases`); the disagreement rate refers to that window, not every historical case. This caps case count, not tokens.
- The advisor must find a specific repeated, solvable pattern. It may return no advice. Each displayed suggestion must cite at least **three distinct disagreeing cases** from its input; invented, duplicated, or agreeing evidence is rejected. Whether those cases substantiate the proposed fix remains model judgment, not a mechanically proven improvement.

Suggested experiments can cover threshold cutoffs, replacement policy wording, missing domain references, target scope, missing source information, controlled classifier/ensemble comparisons, and inconsistent labeling. The advisor is instructed not to invent domain facts, treat scores as probabilities, or weaken injection protections. These are prompt instructions, not guarantees against malicious inputs. Advice is escaped as plain text, has no tools or application actions, and never changes policies or settings. Benchmark any proposed change on an independent held-out set.

When advice is ready, the dashboard and dataset review page display the troll icon beside a rounded speech bubble, with supporting-case links and a validation plan. Dataset authorization also protects advice. Loading a page and saving labels never make inference calls. Completed analyses, including "no advice", are cached until reviewed IDs/versions or analysis/provider settings change; failures remain retryable. Review edits or deletion of any reviewed input immediately hide stale advice. In-flight results retain their original input signature and cannot masquerade as current results after a review edit. Avoid overlapping command runs, which can duplicate provider calls.

Multiple suggestions share a carousel with previous/next arrows, a position indicator, and J/K keycap hints. J moves to the previous suggestion; K moves to the next, without wrapping. With focus inside the review form, J/K keep their Pass/Fail meaning instead; typing never browses advice. With one suggestion, navigation is hidden. Without JavaScript, all suggestions remain readable. Each card explicitly asks you to benchmark the current configuration against the suggested improvement using a ground-truth set, keeping evaluation cases held out from tuning.

The × button dismisses only that suggestion for the signed-in reviewer. Dismissal survives reloads and reanalysis that returns the same suggestion, but does not hide other suggestions or affect other reviewers. Changed suggestions can appear again. Dismissal is an authorized, CSRF-protected POST, not an inference or configuration change.

### Retiring advice when a policy changes

By default, capturing a previously unseen revision of a policy retires older advice for the **same dataset and policy ID**. Threshold, wording, and reference changes all count as revisions. Other gates and datasets are unaffected; the new revision must independently meet the evidence minimum. Retired revisions stay retired after late captures or pruning. When first enabling advice on an existing dataset, the newest captured snapshot per policy is considered current.

Capture is not a deployment signal: importing historical revisions, comparing variants, and rolling deployments can arrive out of order. For exact release-time retirement, dispatch this application-owned event when the active policy ships, even before it has any annotations:

```php
use Vos\TollTroll\Annotations\PolicyDeployed;

event(new PolicyDeployed('support', $deployedPolicy));
```

After the first explicit deployment for a dataset/policy ID, only further `PolicyDeployed` events change its active revision; captures cannot override it. Dispatch the event for rollbacks too, and for every affected dataset. Keep stable IDs for the same gate and distinct IDs for independent gates. A revision is the exact policy snapshot, not a Git commit, a model change, or an application release number. Analysis must be enabled when the event is dispatched. Historical analyses remain stored but hidden, including any analysis that completes after its policy was retired.

Suggestions are encrypted in `toll_troll_annotation_analyses`, but may summarize private source content. They have their own lifetime: when pruning or deleting source records, also delete analyses for each affected dataset with `AnnotationAnalysis::where('dataset', $dataset)->delete()` (import `Vos\TollTroll\Annotations\AnnotationAnalysis`). Even a stale, hidden analysis must be removed for data deletion. Disabling analysis hides notices and stops calls but does not delete saved analyses. Include these summaries in application-owned retention, account deletion, backups, and key rotation.

Reviewer IDs and suggestion hashes are stored in `toll_troll_annotation_advice_dismissals`; deleting an analysis cascades to its dismissals. The revision ledger in `toll_troll_annotation_policy_revisions` contains dataset names, hashed policy IDs, snapshot hashes, and retirement/deployment state, not source text. Delete its rows explicitly on dataset/account deletion. Preserve the ledger during ordinary case pruning so historical advice cannot reappear.

## Keyboard shortcuts

Both clusters are active, arranged for either hand on a QWERTY keyboard:

| Action | Left hand | Right hand |
| --- | --- | --- |
| Select Pass | A | J |
| Select Fail | S | K |
| Select Unknown | D | L |
| Previous case | Q | U |
| Next case | E | O |
| Save | Ctrl/Cmd + Enter | Ctrl/Cmd + Enter |

Selecting a label does **not** save or advance. Save explicitly; the saved case stays open. Previous/next follows the pending-first queue across pages, does not wrap, and reflects the new queue position after saving. Unsaved label or note changes trigger the browser's leave-page warning.

Letter shortcuts ignore text inputs, editable content, modifier combinations, held-key repeats, and IME composition. Ctrl/Cmd + Enter also works in the note field. When multiple advice cards are present, J/K browse them outside the review form; A/S/D still select labels. Turn off **Keyboard shortcuts** below the review form (or in the dashboard's advice bubble) to disable all shortcuts; the preference is remembered in this browser when local storage is available. Ordinary links, radio buttons, and form submission still work without JavaScript. The page's content security policy permits only the package-owned script by its SHA-256 hash, not arbitrary inline scripts from source context.

## Privacy and retention

Context, policy snapshots, predictions, and notes are encrypted at rest with Laravel's application key. Bridge names, application keys, labels, timestamps, and reviewer IDs are plaintext; keep secrets and personal details out of identifying fields. Rendered pages and downloads use no-store headers. Exported JSONL is plaintext and must stay private.

Your application owns consent, redaction, retention, deletion, backup protection, and key rotation. Do not rotate away an encryption key without retaining the ability to decrypt required data. Disabling the provider removes routes and capture listeners but does not delete stored records.

### Optional age-based pruning 🧌

`Annotation` uses Laravel's `MassPrunable` trait. Pruning is disabled by default. In your published `config/toll-troll-annotations.php`, set a positive PHP integer:

```php
'retention_days' => 30, // null disables pruning; zero is not valid.
```

This selects snapshots in **all bridges**, pending or reviewed, whose `created_at` is at or before `now()->subDays(30)`. Reviewing a case does not extend its lifetime. Invalid values throw before deletion; use an integer, not a numeric string from an uncast environment variable.

Preview the affected count before enabling deletion:

```sh
php artisan model:prune --model='Vos\TollTroll\Annotations\Annotation' --pretend
```

TollTroll registers no scheduled task. After approving your retention policy, schedule Laravel's command in your application's `routes/console.php` (and run Laravel's scheduler normally):

```php
use Illuminate\Support\Facades\Schedule;
use Vos\TollTroll\Annotations\Annotation;

Schedule::command('model:prune', ['--model' => [Annotation::class]])->daily();
```

Pass `--model` explicitly: Laravel's default discovery scans application models, not this package. Deletion is permanent and bulk-based; it does not decrypt snapshots or fire individual `pruning`, `deleting`, or `deleted` hooks. The database cascades deletion to review history; keep foreign key enforcement enabled, including SQLite's `foreign_key_constraints` setting. For per-bridge retention or other criteria, use an application-owned cleanup job with an appropriately scoped Eloquent query instead of this global retention setting.

### Account deletion

TollTroll has no account ownership model. Keep an application-owned mapping from each account to its bridge names and sample keys, then delete all matching snapshots when destroying that account:

```php
use Vos\TollTroll\Annotations\Annotation;

Annotation::where('dataset', $bridgeName)
    ->whereIn('sample_key', $annotationKeysForAccount)
    ->delete();
```

Resolve the keys before deleting the application's ownership records; run this for each relevant bridge. It removes every matching context/policy revision and cascades its review history without touching other keys or bridges. `reviewer_id` identifies the person who labeled a case, **not** the account that owns its source data. Do not use it as the ownership filter.

Deleted snapshots can be recreated by another `AnnotationRequested` event. Stop capture for destroyed accounts and account for queued application work that could dispatch it again. Neither pruning nor account cleanup removes previously downloaded exports, benchmark reports, or backups; cover those in your application's retention process. This is not a claim of regulatory compliance.
