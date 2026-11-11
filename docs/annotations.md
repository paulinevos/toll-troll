# 🧌 Human ground truth, opt-in

Annotations ship in **the same `vos/toll-troll` package**. Composer has no feature flags that selectively install code. Only `TollTrollServiceProvider` is auto-discovered; annotations remain inactive unless you explicitly register their provider. No annotation tables are created automatically, and ordinary evaluations never store annotation data.

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

Optional configuration: `php artisan vendor:publish --tag=toll-troll-annotations-config`. The default path is `/toll-troll/annotations`. Preserve the `web` middleware for sessions and CSRF protection; add your application's authentication middleware if you want login redirects. The controller independently requires an authenticated user and per-dataset authorization. There is **no permissive default gate**.

Define this ability in your application provider, using your own access rule:

```php
use Illuminate\Support\Facades\Gate;

Gate::define('review-toll-troll-annotations', function ($user, string $dataset): bool {
    return $user->canReviewAnnotationDataset($dataset); // Application-owned permission check.
});
```

The same ability controls viewing, labeling, and export. Index pages list only authorized datasets; every case lookup is scoped to its dataset. For multiple tenants, choose distinct dataset names and authorize membership; this package does not infer tenancy.

## Submit deliberate snapshots

Dispatch from trusted application code after redacting data you should not retain:

```php
use Illuminate\Support\Facades\Event;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Context;
use Vos\TollTroll\Policy;

Event::dispatch(new AnnotationRequested(
    dataset: 'support-reproduction',
    key: 'ticket-42', // Stable application key, not a credential or personal identifier.
    context: new Context($trustedTargetInstructions, ['conversation' => $redactedTranscript]),
    policy: Policy::reproductionSteps(),
));
```

The listener is synchronous and stores no model prediction. It performs no inference, starts no agent, and queues no work. The exact same dataset/key/context/policy submission is idempotent; repeating it never resets an existing human label. Changed context or policy creates a fresh pending snapshot. Dataset names accept 1–64 letters, digits, underscores, or hyphens.

Capture after your application's transaction commits if a rollback should cancel the submission. Handle listener/database failures according to your application's delivery needs; there is no hidden queue or retry policy. The package does not subscribe to all evaluations or production conversations automatically.

## Review and export

The route shows the frozen policy wording, trusted target instructions, escaped source text, and policy references. References are displayed separately as domain context, not facts supplied by the reporter. They are encrypted with the policy snapshot and exported verbatim; changing reference names or text creates a fresh pending snapshot without resetting earlier labels. Older cases without references still work. Reviewers explicitly choose `pass`, `fail`, or `unknown` and optionally add a note. Unknown is a reviewed judgment; a pending case has no label. No model predictions are displayed to bias the initial review.

Every save records the reviewer identifier and an append-only revision. Stale forms receive HTTP 409 rather than overwriting another review. Label changes never authorize an agent action. The UI shows the latest label; the `toll_troll_annotation_reviews` table retains history for application audits.

Reviewed cases export as JSONL compatible with [the benchmark command](benchmarking.md), one dataset and exact policy snapshot at a time. Pending cases are excluded; unknown labels are included. The benchmark refuses exported policy wording or references that differ from its suite. Keep development and held-out annotation sets separate to avoid tuning and evaluating on the same records.

### Keyboard shortcuts

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

Letter shortcuts ignore text inputs, editable content, modifier combinations, held-key repeats, and IME composition. Ctrl/Cmd + Enter also works in the note field. Turn off **Keyboard shortcuts** below the form to disable all shortcuts; the preference is remembered in this browser when local storage is available. Ordinary links, radio buttons, and form submission still work without JavaScript. The page's content security policy permits only the package-owned script by its SHA-256 hash, not arbitrary inline scripts from source context.

## Privacy and retention

Context, policy snapshots, and notes are encrypted at rest with Laravel's application key. Dataset names, application keys, labels, timestamps, and reviewer IDs are plaintext; keep secrets and personal details out of identifying fields. Rendered pages and downloads use no-store headers. Exported JSONL is plaintext and must stay private.

Your application owns consent, redaction, retention, deletion, backup protection, and key rotation. Do not rotate away an encryption key without retaining the ability to decrypt required data. Disabling the provider removes routes and capture listeners but does not delete stored records.

### Optional age-based pruning 🧌

`Annotation` uses Laravel's `MassPrunable` trait. Pruning is disabled by default. In your published `config/toll-troll-annotations.php`, set a positive PHP integer:

```php
'retention_days' => 30, // null disables pruning; zero is not valid.
```

This selects snapshots in **all datasets**, pending or reviewed, whose `created_at` is at or before `now()->subDays(30)`. Reviewing a case does not extend its lifetime. Invalid values throw before deletion; use an integer, not a numeric string from an uncast environment variable.

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

Pass `--model` explicitly: Laravel's default discovery scans application models, not this package. Deletion is permanent and bulk-based; it does not decrypt snapshots or fire individual `pruning`, `deleting`, or `deleted` hooks. The database cascades deletion to review history; keep foreign key enforcement enabled, including SQLite's `foreign_key_constraints` setting. For per-dataset retention or other criteria, use an application-owned cleanup job with an appropriately scoped Eloquent query instead of this global retention setting.

### Account deletion

TollTroll has no account ownership model. Keep an application-owned mapping from each account to its annotation datasets and sample keys, then delete all matching snapshots when destroying that account:

```php
use Vos\TollTroll\Annotations\Annotation;

Annotation::where('dataset', $dataset)
    ->whereIn('sample_key', $annotationKeysForAccount)
    ->delete();
```

Resolve the keys before deleting the application's ownership records; run this for each relevant dataset. It removes every matching context/policy revision and cascades its review history without touching other keys or datasets. `reviewer_id` identifies the person who labeled a case, **not** the account that owns its source data. Do not use it as the ownership filter.

Deleted snapshots can be recreated by another `AnnotationRequested` event. Stop capture for destroyed accounts and account for queued application work that could dispatch it again. Neither pruning nor account cleanup removes previously downloaded exports, benchmark reports, or backups; cover those in your application's retention process. This is not a claim of regulatory compliance.
