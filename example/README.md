# TollTroll demo

A small Laravel application using the package in the parent directory through a Composer path repository. Package edits are reflected immediately. This is a **local-only playground**, not a production starter: everyone is automatically signed in as the same demo reviewer. Never deploy it or enter private/real customer data. It refuses to boot outside `local` / `testing` and always uses its own `database/demo.sqlite`, ignoring external database credentials.

## Start

Requires PHP 8.3+, Composer, and SQLite / PDO SQLite. From this directory:

```sh
cp .env.example .env
composer install
php artisan key:generate
php artisan demo:seed
php artisan serve --host=127.0.0.1 --port=8000
```

Open the address printed by Artisan. `/` redirects to the annotation dashboard. No Node build, API keys, queue worker, or live inference is needed. The demo is excluded from package release archives with `export-ignore` in the repository's `.gitattributes`.

In an Amp orb, `.agents/setup` installs both Composer projects, creates the demo environment/key only when missing, and migrates/seeds the database. Run `amp orb services ensure` from the repository root to start the declared demo and print its portal URL. The service is declared in `.amp/services.yaml`, so it can be started again after an orb resumes without a shell-managed server. Existing keys, reviews, and dismissals are preserved.

## What's seeded

- **demo-support:** 52 synthetic reports, including 40 reviewed cases, six deliberate prediction/label disagreements, 12 pending cases, and three ready-to-read advice cards (wording, references, and a controlled classifier comparison).
- **demo-getting-started:** eight pending reports; not enough evidence for advice.
- Frozen policy/context/prediction snapshots, classifier scores and reasons, reviewer notes, and initial review history. Review, navigation, revision conflicts, and JSONL export use the real package routes and database.

`demo:seed` applies the package migrations and seeds missing records without overwriting existing reviews. To discard **all local demo data**, run `php artisan migrate:fresh` then `php artisan demo:seed`. Keep the same `.env` encryption key while keeping the database; replacing the key makes existing snapshots unreadable.

## Advice without inference

The demo registers a fixture-backed `AnnotationAdvisor`. It exercises the real eligibility checks, encrypted storage, evidence validation, and dashboard rendering, but the advice text is deterministic: **this does not demonstrate the quality of an AI analysis**. Classifier and paraphraser prompts are also faked with stray-prompt prevention. The demo never needs provider credentials.

Saving a review hides old advice because the reviewed input changed. Run `php artisan toll-troll:analyze-annotations demo-support` to regenerate it through the fixture advisor, provided enough disagreements remain. To integrate real inference into your own application, follow [the package's annotation documentation](../docs/annotations.md#annotation-advice), not this demo's deliberately permissive authentication.

Use the arrows or J/K to browse advice; the counter follows the number of undismissed cards. The × button saves a dismissal for the shared demo reviewer. This persists across reseeding and is visible to everyone using this local demo. In the real module, dismissals are per authenticated reviewer. Each card asks you to benchmark the proposal against the current configuration using held-out ground-truth cases; fixture advice is not evidence that a proposal improves results.
