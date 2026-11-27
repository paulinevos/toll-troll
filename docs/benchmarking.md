# 🧌 Benchmark decisions, not promises

The command evaluates **one policy at a time**, independently of guards, branches, jobs, and annotation storage. Use a separate suite/dataset for each policy. It is an experiment, not an authorization endpoint or an end-to-end agent security benchmark.

## Ground truth

Use UTF-8 JSONL, one object per line:

```json
{"id":"ticket-42","instructions":"Triage application bug reports; never deploy.","sources":{"conversation":"Saving a draft returns HTTP 500. Please investigate."},"expected":"pass"}
```

`expected` is the human judgment about the **suite's policy**, not a general safety label. It is `pass`, `fail`, or `unknown`; unknown means a reviewed case has insufficient evidence. IDs must be unique in the inspected input. IDs, labels, reviewer notes, and other metadata never enter the model prompt. Target instructions are trusted application scope and sources are untrusted context.

The optional annotation UI exports this format, including a `policy` snapshot. When supplied, its ID, criteria, and resolved `references` must match the suite; changing the rubric or reference text requires reviewing the ground truth again. A missing `references` field means an empty array for older exports. Thresholds can change without relabeling.

## Benchmark an advice suggestion

Advice is a proposed experiment, not a validated improvement. Use the following workflow in **your Laravel application**, where TollTroll and your inference providers are configured. The repository's `example/` app deliberately fakes inference; running a benchmark there only demonstrates the mechanics.

### 1. Export and choose your cases

Click **Download ground truth (JSONL)** on the advice card. This exports all reviewed cases for that bridge and exact policy revision, not just the cited disagreements. Store it outside public storage and source control, for example as `storage/app/private/ground-truth.jsonl`. The file includes private context, policy references, notes, and historical predictions; treat it as sensitive.

Include passes, failures, and unknowns, not just cases the classifier got wrong. Deduplicate repeated contexts and resolve conflicting labels before use. Cases the advisor analyzed are **development/tuning data**, not an independent evaluation set. For a held-out check, use separately reviewed cases that were not supplied to the advisor, keeping the policy snapshot identical. Keep related reports and repeated runs in the same split. The export does not create a held-out split for you; if none exists, describe the result as exploratory and collect independent cases before claiming improvement.

### 2. Create a baseline and proposed threshold profile

Save the following **trusted local PHP file** as `storage/app/private/advice-suite.php`, next to your selected `ground-truth.jsonl`. It reads the frozen policy from the first exported record; the command checks the remaining selected records against it. Never load a suite supplied by an untrusted user.

```php
<?php

use Vos\TollTroll\Policy;

$first = json_decode(
    (new SplFileObject(__DIR__.'/ground-truth.jsonl'))->fgets(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$snapshot = $first['policy'];
$policy = new Policy(
    $snapshot['id'], $snapshot['criteria'],
    $snapshot['passThreshold'], $snapshot['failThreshold'],
    $snapshot['references'] ?? [],
);

// Replace these illustrative cutoffs with the proposed experiment's values.
$proposed = $policy->withThresholds(pass: 0.85, fail: 0.2);
$baseline = [
    'aggregation' => 'unanimous', // Use 'veto' when testing a guard policy.
    'weights' => ['original' => 1],
    'pass' => $policy->passThreshold,
    'fail' => $policy->failThreshold,
];

return [
    'policy' => $policy,
    // Omitted models inherit the current TollTroll classifier configuration.
    'repeats' => 0,
    'variants' => 0,
    'profiles' => [
        'baseline' => $baseline,
        'proposed' => array_replace($baseline, [
            'pass' => $proposed->passThreshold,
            'fail' => $proposed->failThreshold,
        ]),
    ],
];
```

This is an original-only, isolated-policy comparison. Each configured classifier is measured separately; production uses one classifier per bridge. Explicitly set `models` if you need to reproduce a historical or bridge-specific classifier configuration; the export does not configure providers from its old predictions. To test repeated judgments or paraphrases, change `repeats`, `variants`, and profile weights deliberately, using the settings below. Keep everything except the proposed change fixed.

### 3. Preview, then run

From your Laravel application's root:

```sh
php artisan toll-troll:benchmark storage/app/private/advice-suite.php storage/app/private/ground-truth.jsonl --dry-run
php artisan toll-troll:benchmark storage/app/private/advice-suite.php storage/app/private/ground-truth.jsonl --output=storage/app/private/advice-comparison.json
```

Dry-run validates the inputs and displays eligible/skipped counts and the maximum SDK prompt calls without inference. The run then asks you to approve sending those cases to the configured providers. Both profiles score the **same newly collected judgments**, so adding the proposed threshold profile does not double inference. Historical predictions in the annotation export are not replay captures. The defaults select at most 100 eligible cases and skip oversized sources/references; inspect the counts, or deliberately set `--limit` and `--max-bytes` on both commands. Use a fresh output filename for each run.

For further threshold-only experiments, edit only the profiles and rescore the completed report without more inference:

```sh
php artisan toll-troll:benchmark storage/app/private/advice-suite.php --replay=storage/app/private/advice-comparison.json --output=storage/app/private/advice-rescored.json
```

### 4. Compare results before changing anything

Compare the `baseline` and `proposed` rows for each model: **False pass**, **Withheld pass**, **Unknown**, **Error**, and latency. The private JSON report includes per-case decisions: inspect both improvements and regressions, rather than choosing on accuracy alone. Follow the advice card's specific **Check** as well. Repeatedly tuning on the same cases is not held-out validation. No command edits your live policy or dispatches application work.

### If the advice is not a threshold change

- **Classifier comparison:** keep the policy and scoring profile fixed. Add named `current` and `candidate` entries to the suite's `models` array with explicit configured provider/model pairs, then collect a new report on the same selected cases. A changed model cannot use offline replay.
- **Policy wording or domain references:** copy the suite for the candidate policy. The CLI intentionally rejects an export whose policy criteria/references do not match. Review every selected case against the candidate rubric and create a candidate dataset carrying that exact policy snapshot and its reviewed labels. Do not just remove or overwrite the export's `policy` field to bypass the check. Run baseline and candidate collections separately, preserve paired case identities where possible, and disclose label changes: different rubrics/labels are not a like-for-like accuracy comparison. Even apparently clarifying wording needs label review under the current command's contract.
- **Target instructions or input quality:** prepare a separately reviewed candidate dataset with the proposed trusted instructions or newly collected source information. Do not invent missing source facts. Preserve the policy snapshot if it is unchanged, keep baseline cases untouched, and collect a new report for each version.
- **Label quality:** reconcile the human labels first, export the corrected ground truth, and only then evaluate a proposed configuration. Better agreement with inconsistent labels is not an improvement.

## Collect once, compare many ways

Copy [the example suite](../examples/benchmark.php) into your Laravel application. A suite is **trusted executable PHP**, not uploaded user input. Set its policy, SDK provider/model entries, counts, and scoring profiles. Null provider/model uses the application's SDK defaults; reports retain actual provider/model metadata as well as requested configuration.

The suite's `policy` accepts the built-in value object or an application implementation of [`Contracts\Policy`](../README.md#custom-policies). The command freezes its resolved contract values when loading the suite, so all records use the same policy and references; extra implementation fields never enter reports or prompts.

Use a named model matrix to compare native decision classifiers; this is a benchmark experiment, not a production ensemble:

```php
return [
    'policy' => $yourPolicy,
    'models' => [
        'current' => ['provider' => 'typesafe', 'model' => null],
        'candidate' => ['provider' => 'openai', 'model' => null],
    ],
    'timeout' => 30,
    'profiles' => [
        'original' => ['aggregation' => 'unanimous', 'weights' => ['original' => 1], 'pass' => 0.8, 'fail' => 0.2],
    ],
];
```

Without `models`, the suite uses `toll-troll.classifier` as one model named `classifier_1`; without `timeout`, it uses `toll-troll.timeout`. Explicit suite values override these defaults. To reproduce a bridge's override, copy its provider/model pair and timeout into the suite. Classifier drivers must be `typesafe`, `openai`, or `gemini`, including named SDK configurations using those drivers. Null provider/model entries explicitly select SDK classification defaults. The resolved model list and timeout are stored in the capture, so replay rejects changes to them. There are no policy inference attributes or suite `config` objects. Benchmark `variants`, `repeats`, and `paraphraser` remain experiment settings; `timeout` applies to both classification and rewrites in this command. Unlike bridge evaluation, each benchmark judges a single isolated policy and collects repeated samples independently.

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

Current reports use format 2 and judgment-only `pass`/`fail`/`unknown` classification. Old format-1 text-agent reports cannot be replayed with the new probability semantics; collect a new report instead. Existing ground-truth exports can still be used when their policy snapshot matches the suite.

Resolve references once in the trusted suite's `Policy`, as shown in the [domain-reference example](../README.md#-domain-references). All records, models, and repeats use that same text; only report sources are paraphrased. Captures store the resolved text, not paths or retrieval callbacks. Freeze documents locally when comparing runs so changing an upstream document does not silently change the experiment. Use separate suites for different reference snapshots. References, including private code and documents, are sent to each selected classifier and stored in the private report; approve and redact them along with sources.

## Weights and thresholds

Each profile supplies `aggregation`, `weights`, `pass`, and `fail`. Thresholds satisfy `0 <= fail < pass <= 1`. Scores are P(pass), including for the no-injection policy. Failure requires P(fail) ≥ 1 − the fail cutoff, not merely low P(pass): the remaining probability may belong to `unknown`.

Groups are `original` (the first judgment), `repeated` (additional original judgments), and `paraphrased` (judgments of rewrites). Missing or zero-weight groups are excluded from that profile. Positive weights require the group to have been collected.

- **weighted:** calculate group means and their weighted mean separately for P(pass) and P(fail). Pass when weighted P(pass) reaches the pass cutoff; fail when weighted P(fail) reaches 1 − the fail cutoff; otherwise unknown. Weights are normalized by their sum. Adding more repeated judgments does not automatically increase that group's influence. For example, original P(pass) 0.9, repeats 0.3/0.5, paraphrase 0.2, with weights 1/1/2, gives weighted P(pass) approximately 0.425.
- **unanimous:** all selected P(pass) values must reach the pass cutoff to pass; all P(fail) values must reach 1 − the fail cutoff to fail; otherwise unknown. Positive weights select groups, but their magnitudes do not change this rule.
- **veto:** like unanimous, except any selected definite failure vetoes. This resembles the production guard reducer; it does not benchmark the complete production pipeline.

Any selected inference/validation failure produces `error`, and an explicit unknown choice (null score) produces `unknown`; neither is silently dropped or interpreted as a zero. For veto, a definite failure takes precedence over errors or unknowns, as in the guard reducer. A failed rewrite remains an error placeholder in its selected group. Original-only profiles can still be scored if an unused rewrite failed.

Weighted profiles can average away a genuine violation. They are **offline research only**, never a replacement for the production gate's all-pass/veto rule. Model probabilities and their averages are not calibrated guarantees of correctness, and independent calls to one model are not independent security guarantees.

## Read the whole report

Reports contain raw validated judgments, selected context, generated rewrites, criteria, prompt-code hashes, metadata, elapsed times, SDK token usage, per-case decisions, and confusion tables. `false_passes` counts any expected fail/unknown that passes. `withheld_passes` counts expected passes that do not pass, including errors and abstentions. Errors are incorrect outcomes in accuracy, never removed from its denominator.

Profile latency sums its selected serial calls, including shared rewrite time when used; p50/p95 are nearest-rank percentiles of those case times. Token totals are SDK-reported, not invoice totals. Failures can cost money without reporting usage. Shared rewrites are attributed to each model/profile that would need them in isolation, so summing profile costs double-counts collection work. Calculate dollar costs from your provider's actual pricing and invoices, not a universal conversion.

Keep reports and exports outside public storage and out of source control. Tune weights/thresholds on a development split, freeze them, and evaluate once on held-out data. Include realistic benign cases and report uncertainty; a tiny attack-only set cannot establish a false-positive rate or protection against adaptive probing. 🧌
