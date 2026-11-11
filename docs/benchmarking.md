# 🧌 Benchmark decisions, not promises

The command evaluates **one policy at a time**, independently of guards, branches, jobs, and annotation storage. Use a separate suite/dataset for each policy. It is an experiment, not an authorization endpoint or an end-to-end agent security benchmark.

## Ground truth

Use UTF-8 JSONL, one object per line:

```json
{"id":"ticket-42","instructions":"Triage application bug reports; never deploy.","sources":{"conversation":"Saving a draft returns HTTP 500. Please investigate."},"expected":"pass"}
```

`expected` is the human judgment about the **suite's policy**, not a general safety label. It is `pass`, `fail`, or `unknown`; unknown means a reviewed case has insufficient evidence. IDs must be unique in the inspected input. IDs, labels, reviewer notes, and other metadata never enter the model prompt. Target instructions are trusted application scope and sources are untrusted context.

The optional annotation UI exports this format, including a `policy` snapshot. When supplied, its ID, criteria, and resolved `references` must match the suite; changing the rubric or reference text requires reviewing the ground truth again. A missing `references` field means an empty array for older exports. Thresholds can change without relabeling.

## Collect once, compare many ways

Copy [the example suite](../examples/benchmark.php) into your Laravel application. A suite is **trusted executable PHP**, not uploaded user input. Set its policy, SDK provider/model entries, counts, and scoring profiles. Null provider/model uses the application's SDK defaults; reports retain actual provider/model metadata as well as requested configuration.

```sh
php artisan toll-troll:benchmark examples/benchmark.php storage/app/private/cases.jsonl --dry-run
php artisan toll-troll:benchmark examples/benchmark.php storage/app/private/cases.jsonl --output=storage/app/private/run.json
```

Collection asks before sending context to providers. `--yes` approves it for unattended runs. The command never calls `dispatch()` or emits `ReviewRequired`, and classifiers have no tools.

The default selection is the first **100 eligible cases** in file order. `--limit` changes this. `--max-bytes=4000` skips oversized inputs, counting UTF-8 bytes across all source texts **plus all policy reference texts**; it never truncates. If references alone leave no room for sources, validation fails before inference. This excludes trusted instructions and request overhead, so it is **not a token or dollar budget**. Generated rewrites that exceed the limit together with the unchanged references become errors. Skipped IDs are reported; selection stops once the eligible-record limit is reached. Sampling, deduplication across splits, provider quotas, and a monetary cap remain application responsibilities.

For each case, the command generates `variants` rewrites once, shares them across all models, and collects one original judgment, `repeats` additional original judgments, and one judgment per rewrite. Calls are sequential, with no command-level retries. Invalid output remains an error, not a reason to retry until a desirable verdict appears. SDK/provider retries or failover can still add HTTP requests. Dry-run prints an upper bound on SDK prompt calls before inference.

Change only `profiles` and run:

```sh
php artisan toll-troll:benchmark examples/benchmark.php --replay=storage/app/private/run.json --output=storage/app/private/rescored.json
```

Replay makes **zero inference calls**. It requires a complete capture with the same policy wording, resolved references, models, counts, paraphraser, and timeout. A changed model, reference, or sample count requires collection. Output files must not already exist; the command creates them with private permissions. Progress is saved after each complete case; interrupted reports remain marked incomplete and cannot be replayed as finished runs. This version does not automatically resume partial inference.

Resolve references once in the trusted suite's `Policy`, as shown in the [domain-reference example](../README.md#-domain-references). All records, models, and repeats use that same text; only report sources are paraphrased. Captures store the resolved text, not paths or retrieval callbacks. Freeze documents locally when comparing runs so changing an upstream document does not silently change the experiment. Use separate suites for different reference snapshots. References, including private code and documents, are sent to each selected classifier and stored in the private report; approve and redact them along with sources.

## Weights and thresholds

Each profile supplies `aggregation`, `weights`, `pass`, and `fail`. Thresholds satisfy `0 <= fail < pass <= 1`. Scores always measure **policy satisfaction**: high means more likely to pass, including for the no-injection policy.

Groups are `original` (the first judgment), `repeated` (additional original judgments), and `paraphrased` (judgments of rewrites). Missing or zero-weight groups are excluded from that profile. Positive weights require the group to have been collected.

- **weighted:** calculate the mean score within each selected group, then the weighted mean of those group means. Weights are normalized by their sum. Adding more repeated judgments does not automatically increase that group's influence. For example, original 0.9, repeat scores 0.3/0.5, paraphrase 0.2, with weights 1/1/2, gives approximately 0.425.
- **unanimous:** all selected scores must reach the pass cutoff to pass; all must reach the fail cutoff to fail; otherwise unknown. Positive weights select groups, but their magnitudes do not change this rule.
- **veto:** like unanimous, except any selected failing score vetoes. This resembles the production guard reducer; it does not benchmark the complete production pipeline.

Any selected inference/validation failure produces `error`, and a null score produces `unknown`; neither is silently dropped or interpreted as a zero. For veto, a definite failure takes precedence over errors, as in the guard reducer. A failed rewrite remains an error placeholder in its selected group. Original-only profiles can still be scored if an unused rewrite failed.

Weighted profiles can average away a genuine violation. They are **offline research only**, never a replacement for the production gate's all-pass/veto rule. Scores and averages are not calibrated probabilities, and independent calls to one model are not independent security guarantees.

## Read the whole report

Reports contain raw validated judgments, selected context, generated rewrites, criteria, prompt-code hashes, metadata, elapsed times, SDK token usage, per-case decisions, and confusion tables. `false_passes` counts any expected fail/unknown that passes. `withheld_passes` counts expected passes that do not pass, including errors and abstentions. Errors are incorrect outcomes in accuracy, never removed from its denominator.

Profile latency sums its selected serial calls, including shared rewrite time when used; p50/p95 are nearest-rank percentiles of those case times. Token totals are SDK-reported, not invoice totals. Failures can cost money without reporting usage. Shared rewrites are attributed to each model/profile that would need them in isolation, so summing profile costs double-counts collection work. Calculate dollar costs from your provider's actual pricing and invoices, not a universal conversion.

Keep reports and exports outside public storage and out of source control. Tune weights/thresholds on a development split, freeze them, and evaluate once on held-out data. Include realistic benign cases and report uncertainty; a tiny attack-only set cannot establish a false-positive rate or protection against adaptive probing. 🧌
