## TollTroll 🧌

`vos/toll-troll` evaluates agent context against policies before dispatching application-owned Laravel jobs. It also benchmarks isolated policies against human-labeled JSONL. Use the Laravel AI SDK's configured providers; TollTroll does not manage credentials or select a vendor.

### Integrating policy gates

- Use `Vos\TollTroll\Context` with trusted target-agent instructions and a named array of untrusted source strings. Keep the report/conversation in `sources`. Put domain documents or code in `Policy(references: [...])`, never in instructions or policy criteria. References are named resolved UTF-8 strings; resolve them with `File`, `Storage`, or application retrieval code before constructing the policy. Handle retrieval failures and redact/limit data before inference; callbacks and empty text are rejected.
- References are data, not authority or facts reported by the user. State explicitly when a policy may use them to supply a fact; report-completeness policies must not infer missing reporter details from documentation. References stay unchanged across samples and enter injection screening, not paraphrasing. Preserve them when capturing annotations or benchmarks.
- Resolve `Vos\TollTroll\TollTroll` from the container. `evaluate()` runs the mandatory injection guard before sequential policies in each independent branch. `dispatch()` is a separate operation; mapped factories construct application jobs implementing `ShouldQueue`.
- Require `$evaluation->allows($branch)` for the relevant branch, not an individual score. Errors, uncertainty, or a failed guard withhold work. Keep tool authorization and consequential-action approval in the application; a pass is not proof against prompt injection.
- Inference is synchronous. Put evaluation inside an application job when background processing is required. Configure credentials through `config/ai.php` and sample counts/models through `config/toll-troll.php`; zero paraphrases is the default.
- Scores measure policy satisfaction, including for the injection guard. Use `Policy::withThresholds(pass: ..., fail: ...)`, with `0 <= fail < pass <= 1`. Thresholds are uncalibrated cutoffs, not probabilities.
- Keep the evaluated snapshot unchanged through dispatch. Implement side-effect idempotency and reauthorize if context, permissions, or instructions change. `assess()` and `paraphrase()` are diagnostics without the full guard pipeline, not authorization APIs.
- Read `vendor/vos/toll-troll/examples/` for triage, review notifications, and Laravel AI human tool approval; read the package README for dispatch and review-event semantics. Fake `Vos\TollTroll\Agents\Classifier` and `Paraphraser` in application tests instead of making paid inference calls.

### Opt-in annotation sets

- Register `Vos\TollTroll\Annotations\AnnotationServiceProvider` only when annotations are requested. Publish `toll-troll-annotations-migrations` and follow the application's migration approval process. Evaluation itself needs no tables.
- Define the `review-toll-troll-annotations` Gate for the authenticated reviewer and dataset. Preserve session/CSRF middleware. The package denies dataset access without authorization.
- Dispatch `AnnotationRequested(dataset, key, context, policy)` from trusted application code after redacting unnecessary personal or secret data. Capture is synchronous and idempotent for identical snapshots; it neither runs inference nor queues an agent. Dataset/key/reviewer identifiers remain plaintext even though context, policy, and notes are encrypted.
- Export reviewed JSONL for the exact policy revision. Pending cases are excluded; `unknown` is a human label, not an unreviewed case. Read `vendor/vos/toll-troll/docs/annotations.md` before wiring capture or retention.
- Retention is opt-in: set `toll-troll-annotations.retention_days` to a positive integer, preview `model:prune --model='Vos\TollTroll\Annotations\Annotation' --pretend`, and obtain approval before scheduling permanent deletion. It expires pending and reviewed snapshots in all datasets by capture age and cascades review history with foreign keys enabled. Account deletion needs the application's dataset/key ownership mapping, not `reviewer_id`; exports, reports, and backups require separate cleanup.

### Benchmarking policies

- Read `vendor/vos/toll-troll/docs/benchmarking.md` and copy `vendor/vos/toll-troll/examples/benchmark.php` to an application-owned suite. A suite is trusted executable PHP; never load an uploaded suite. Choose one policy, configured SDK models, repeat/variant counts, and profiles.
- Use UTF-8 JSONL records with unique `id`, trusted `instructions`, named `sources`, and human `expected` labels (`pass`, `fail`, `unknown`). Labels describe satisfaction of that policy, not general safety. Exported policy ID/criteria/references must match the suite. Keep reports and exports in private storage and out of source control.
- Run a dry run first. Tell the user the eligible/skipped record counts and SDK call bound, selected providers/models, sample settings, and data destination before live collection. Agree on an inference budget and approved data before sending it; `--yes` is for an already-approved unattended run, not a way to bypass approval.

@verbatim
<code-snippet name="Preview, collect, and replay a policy benchmark" lang="shell">
php artisan toll-troll:benchmark benchmarks/support.php storage/app/private/cases.jsonl --limit=20 --max-bytes=4000 --dry-run
php artisan toll-troll:benchmark benchmarks/support.php storage/app/private/cases.jsonl --limit=20 --max-bytes=4000 --output=storage/app/private/run.json
# After changing only scoring profiles/thresholds in the suite:
php artisan toll-troll:benchmark benchmarks/support.php --replay=storage/app/private/run.json --output=storage/app/private/rescored.json
</code-snippet>
@endverbatim

- `--limit` defaults to 100 eligible records in file order. `--max-bytes` defaults to 4,000 UTF-8 bytes across source and policy reference texts combined; oversized records are skipped, not truncated. It excludes trusted instructions and is not a token/dollar limit. The command does not enforce a monetary cap.
- Per case, the SDK call upper bound is `variants + models × (1 + repeats + variants)`. `repeats` means additional original judgments. Rewrites are shared across models. Calls are sequential with no command-level retry or automatic resume; SDK retries/failover may add HTTP calls. Preserve incomplete reports and obtain approval before starting another paid collection.
- Change `profiles` to compare weights/thresholds offline using `--replay`; it makes zero inference calls. Keep capture policy wording, resolved references, models, paraphraser, repeat/variant counts, and timeout identical. Freeze retrieved text when comparing runs; reference changes require collection and review of affected labels. Choose a new output path: existing reports are never overwritten, and incomplete captures cannot be replayed.
- Weights select `original`, `repeated`, and `paraphrased` groups. `weighted` averages group means, so adding repeats does not increase that group's influence. `unanimous` requires agreement; `veto` lets any failing score block passage. Positive weight magnitudes matter only for `weighted`. These profiles never alter production gating or dispatch jobs.
- Tune on development data, freeze settings, then evaluate on separate held-out data. Include realistic benign cases, scope-sensitive requests, and incomplete reports alongside attacks. Retain errors and unknowns in denominators. Report false passes, withheld passes, confusion, latency, token usage, sample size, and limitations; SDK usage is not an invoice. Do not present model scores, agreement, or zero observed misses as calibrated security guarantees.
