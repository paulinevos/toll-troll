<?php

namespace Vos\TollTroll\Console;

use Illuminate\Console\Command;
use Throwable;
use Vos\TollTroll\Annotations\AnalyzeAnnotations;
use Vos\TollTroll\Annotations\Annotation;

class AnalyzeAnnotationsCommand extends Command
{
    protected $signature = 'toll-troll:analyze-annotations {dataset? : Analyze only this dataset}';

    protected $description = 'Find evidence-backed policy tuning suggestions in reviewed annotations';

    public function handle(AnalyzeAnnotations $analyzer): int
    {
        if (! config('toll-troll-annotations.analysis.enabled')) {
            $this->components->info('Annotation analysis is disabled. Enable toll-troll-annotations.analysis.enabled first.');

            return self::SUCCESS;
        }

        $datasets = $this->argument('dataset') !== null ? [$this->argument('dataset')]
            : Annotation::query()->distinct()->pluck('dataset');
        $status = self::SUCCESS;
        foreach ($datasets as $dataset) {
            try {
                $analyzer->analyze($dataset);
                $this->components->info('Checked dataset: '.$dataset);
            } catch (Throwable $error) {
                // Provider errors may contain private annotation content; do not print their message.
                $this->components->error('Annotation analysis failed ('.$error::class.').');
                $status = self::FAILURE;
            }
        }

        return $status;
    }
}
