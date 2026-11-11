<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Vos\TollTroll\Annotations\AnalyzeAnnotations;
use Vos\TollTroll\Annotations\Annotation;
use Vos\TollTroll\Annotations\AnnotationRequested;
use Vos\TollTroll\Context;
use Vos\TollTroll\Policy;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['demo-support' => 52, 'demo-getting-started' => 8] as $dataset => $count) {
            for ($i = 0; $i < $count; $i++) {
                $key = sprintf('DEMO-%03d', $i + 1);
                $corrected = $i < 6;
                $report = match (true) {
                    $corrected => 'On Firefox 132, open a new draft, type Hello, then click Save. The editor closes without saving. It should keep the draft. I can repeat this on a new account.',
                    $i % 3 === 0 => 'Saving is broken. Please fix it.',
                    $i % 3 === 1 => "Environment: Chrome 131, macOS 15.\n1. Open a draft.\n2. Enter Hello.\n3. Click Save.\nExpected: draft saved. Actual: the editor closes and the text is lost.",
                    default => 'Could you add autosave? I have not seen a crash, but I would like my drafts saved automatically.',
                };
                $context = new Context('Triage incoming support reports. Propose fixes, but never deploy or change customer data.', ['ticket' => $report."\nSynthetic ticket: ".$key]);
                Event::dispatch(new AnnotationRequested($dataset, $key, $context, Policy::reproductionSteps()));
                $case = Annotation::where('dataset', $dataset)->where('sample_key', $key)->sole();
                if ($case->prediction !== null) {
                    continue; // Reseeding preserves reviews, notes, and their audit history.
                }

                $expected = $corrected || $i % 3 === 1 ? 'pass' : 'fail';
                $predicted = $corrected ? 'fail' : $expected;
                $reviewed = $dataset === 'demo-support' && $i < 40;
                $case->fill([
                    'prediction' => ['evaluation_id' => 'synthetic-'.$dataset.'-'.$key, 'branch' => 'bug-triage', 'verdict' => $predicted,
                        'assessments' => [['sample' => 'original', 'verdict' => $predicted, 'score' => $predicted === 'pass' ? 0.92 : 0.15,
                            'reason' => $corrected ? 'Reproduction steps are not presented as an explicit list.' : ($predicted === 'pass' ? 'Steps, environment, expected and actual behavior are supplied.' : 'Necessary reproduction details are missing.'),
                            'provider' => 'demo', 'model' => 'fixture-only', 'error' => null,
                            'missing' => $predicted === 'pass' ? [] : ['Reproduction steps'], 'evidence' => []]],
                        'variants' => [], 'errors' => []],
                    'label' => $reviewed ? $expected : null, 'version' => $reviewed ? 1 : 0,
                    'reviewer_id' => $reviewed ? 'demo-reviewer' : null, 'reviewed_at' => $reviewed ? now() : null,
                    'note' => $reviewed && $corrected ? 'Synthetic example: prose includes concrete steps, input, and environment. A numbered list should not be required.' : null,
                ])->save();
                if ($reviewed) {
                    DB::table('toll_troll_annotation_reviews')->insert([
                        'annotation_id' => $case->id, 'version' => 1, 'label' => $expected, 'note' => $case->getAttributes()['note'],
                        'reviewer_id' => 'demo-reviewer', 'created_at' => now(),
                    ]);
                }
            }
        }

        app(AnalyzeAnnotations::class)->analyze('demo-support');
    }
}
