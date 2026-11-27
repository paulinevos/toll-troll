# Benchmark your policies

Before changing a classifier or its scoring settings, check how it handles cases you've already labeled. Start with one policy, collect its judgments, then replay them to try different thresholds without more inference. Neither collection nor replay dispatches application jobs or changes live policies.

Run these commands in **your Laravel application**, with TollTroll and its providers configured. The repository's [demo](../example/README.md) is useful for trying the workflow, but it fakes inference, so it can't tell you how well a model performs.

## 1. Prepare labeled cases

Create a private UTF-8 JSONL file, one object per line:

```json
{"id":"ticket-42","instructions":"Triage application bug reports; never deploy.","sources":{"conversation":"Saving a draft returns HTTP 500. Please investigate."},"expected":"pass"}
```

`expected` is the human judgment for the **policy you are testing**: `pass`, `fail`, or `unknown` (insufficient information). IDs must be unique. Labels and record metadata are not sent to the classifier.

The [annotation dashboard](annotations.md) exports this format with a policy snapshot. Its ID, criteria, and references must match the suite; review labels again when wording or references change. Threshold-only changes do not require relabeling.

Include realistic passing, failing, and unknown cases. Keep related reports and repeated runs in the same split. Tune on development data, then check separate held-out cases; the command does not deduplicate or split data for you.

## 2. Create a suite

Save a trusted local PHP file, for example `storage/app/private/bug-suite.php`:

```php
<?php

use Vos\TollTroll\Policy;

return [
    'policy' => Policy::bugReport(),
    'profiles' => [
        'baseline' => [
            'aggregation' => 'unanimous',
            'weights' => ['original' => 1],
            'pass' => 0.8,
            'fail' => 0.2,
        ],
    ],
];
```

This uses the global TollTroll classifier and timeout with one original judgment per case. A suite is executable PHP: never load one supplied by an untrusted user. The policy can also implement [`Contracts\Policy`](configuration.md#custom-policy-objects).

## 3. Preview and collect

```sh
php artisan toll-troll:benchmark storage/app/private/bug-suite.php storage/app/private/cases.jsonl --dry-run
php artisan toll-troll:benchmark storage/app/private/bug-suite.php storage/app/private/cases.jsonl --output=storage/app/private/baseline.json
```

Dry-run validates inputs and shows eligible/skipped counts and an upper bound on SDK prompt calls. Collection asks for approval before sending context to providers; `--yes` approves unattended runs. Provider retries may add HTTP requests.

| Option | Default | Purpose |
| --- | --- | --- |
| `--limit` | `100` | First eligible cases in file order. |
| `--max-bytes` | `4000` | Maximum combined UTF-8 source and policy-reference bytes per case. Oversized cases are skipped, not truncated. |
| `--output` | Required except for dry-run | New report path in an existing private directory; existing files are not overwritten. |
| `--replay` | None | Completed benchmark report to rescore without inference. |

Check the skipped count before comparing results—you may be testing fewer cases than you expected. The byte limit excludes instructions and request overhead; it isn't a token or monetary budget. Oversized rewrites become errors. Progress is saved after each complete case, but interrupted reports cannot be replayed or automatically resumed.

## 4. Compare thresholds without more inference

Add a second profile to the same suite, keeping the baseline:

```php
'stricter' => [
    'aggregation' => 'unanimous',
    'weights' => ['original' => 1],
    'pass' => 0.9,
    'fail' => 0.15,
],
```

Then rescore the saved judgments:

```sh
php artisan toll-troll:benchmark storage/app/private/bug-suite.php --replay=storage/app/private/baseline.json --output=storage/app/private/comparison.json
```

Only profiles and thresholds may change during replay. Changing the policy wording, references, models, counts, paraphraser, or timeout requires a new collection. Replay requires a complete format-2 report; old format-1 reports need fresh collection. Annotation exports are input cases, not replay reports.

## Collect once, compare many ways

For model and sample comparisons, extend the suite or adapt [the example suite](../examples/benchmark.php):

| Suite key | Default | Purpose |
| --- | --- | --- |
| `policy` | Required | One policy to test, independently of production guards and branches. |
| `models` | Global `toll-troll.classifier`, named `classifier_1` | Named provider/model pairs. Each is measured separately, not as an ensemble. |
| `timeout` | `toll-troll.timeout` | Per-call timeout for classification and rewrites. |
| `repeats` | `0` | Additional judgments of the original, beyond the first. |
| `variants` | `0` | Additional paraphrases, shared across models. |
| `paraphraser` | `['provider' => null, 'model' => null]` | Text provider/model generating rewrites. |
| `profiles` | Required | Named scoring settings applied to the same collected judgments. |

For example, compare configured classifiers with:

```php
'models' => [
    'current' => ['provider' => 'typesafe', 'model' => null],
    'candidate' => ['provider' => 'openai', 'model' => null],
],
```

Classifiers must use the `typesafe`, `openai`, or `gemini` driver. Include both engine keys; null selects SDK defaults. Suites do not inherit bridge-specific overrides: copy that classifier and timeout explicitly when reproducing a bridge configuration.

References are fixed policy text for all cases and models. Resolve them before collection, keep snapshots stable between runs, and approve sending them to every selected provider. Only sources are paraphrased.

## Weights and thresholds

Each profile supplies `aggregation`, `weights`, `pass`, and `fail`. Cutoffs must satisfy `0 <= fail < pass <= 1`. Passing requires P(pass) at the pass cutoff; failure requires P(fail) at 1 − the fail cutoff, not merely low P(pass).

Weight groups are `original` (first judgment), `repeated` (additional originals), and `paraphrased` (rewrites). Omitted or zero-weight groups are ignored; positive weights require collected samples.

| Aggregation | Rule |
| --- | --- |
| `unanimous` | All selected samples must pass to pass, or all fail to fail. Otherwise unknown. Use for a production branch-policy comparison. |
| `veto` | Any selected failure vetoes; passage still requires all samples to pass. Use for a production guard-policy comparison. |
| `weighted` | Compare weighted group means of P(pass) and P(fail) with the cutoffs. Repeats do not gain influence simply by being more numerous. |

Errors remain errors, except that a veto failure takes precedence. Otherwise, an explicit unknown choice makes a profile unknown, even if other samples have high scores. Weighted scoring is an experiment, not an alternative production gate setting. Model probabilities and repeated calls do not guarantee correctness.

## Read the report

The command prints per-model/profile counts and latency. The private JSON report includes judgments, context, rewrites, provider metadata, token usage, and per-case decisions.

- **False pass:** expected fail/unknown, but passed.
- **Withheld pass:** expected pass, but failed, abstained, or errored.
- **Unknown / Error:** uncertain decisions and execution failures. Errors count as incorrect in accuracy.
- **p95 seconds:** 95th-percentile selected serial call time, including rewrites when used.

Inspect regressions as well as improvements. Token totals are provider-reported, not invoice totals; failures may cost money without reporting usage. Shared rewrites are attributed to each profile that needs them, so summing profile costs double-counts work. Keep reports private and out of source control.

## Benchmark an advice suggestion

1. Download ground-truth JSONL from the advice card and save it privately. Cases seen by the advisor are **tuning data**; use independently labeled held-out cases to evaluate a final choice.
2. Build the suite with the exact exported policy. For a threshold experiment, keep the baseline profile and add the proposed cutoffs.
3. Dry-run, collect, and compare both profiles on the same new judgments. Historical predictions in the export are not replayed. Further threshold experiments can use offline replay.
4. Review false passes, withheld passes, errors, latency, and the card's validation plan before changing live settings.

To load the frozen policy from the export in a **trusted application-owned suite**:

```php
$first = json_decode(
    (new SplFileObject(__DIR__.'/ground-truth.jsonl'))->fgets(),
    true,
    flags: JSON_THROW_ON_ERROR,
);
$snapshot = $first['policy'];
$policy = new \Vos\TollTroll\Policy(
    $snapshot['id'], $snapshot['criteria'],
    $snapshot['passThreshold'], $snapshot['failThreshold'],
    $snapshot['references'] ?? [],
);
```

Use `$policy` in the suite's `policy` entry and its thresholds in the baseline profile. Keep the JSONL beside the suite as `ground-truth.jsonl`; the command checks the remaining selected policy snapshots. Set `models` explicitly for historical or bridge-specific classifier settings; exports do not configure providers from old predictions.

Other advice requires different experiments:

- **Classifier:** keep cases, policy, and profile fixed; add the candidate to `models` and collect again. Test other bridge policies, including guards, before adoption.
- **Wording or references:** review every selected case against the new rubric and create a matching candidate dataset. Do not strip the snapshot to bypass validation. Collect baseline and candidate separately and disclose any label changes.
- **Instructions or input quality:** prepare a separately reviewed dataset with the proposed instructions or actual additional source information; never invent missing facts.
- **Label quality:** reconcile inconsistent labels before evaluating configuration changes.

No command adopts a suggestion automatically. If you lack independent evaluation cases, treat the comparison as exploratory rather than evidence of improvement.
