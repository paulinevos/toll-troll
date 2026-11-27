<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Vos\TollTroll\Console\AnalyzeAnnotationsCommand;

class AnnotationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/toll-troll-annotations.php', 'toll-troll-annotations');

        foreach ([Annotation::class => MongoDB\Annotation::class, AnnotationAnalysis::class => MongoDB\AnnotationAnalysis::class] as $model => $document) {
            $this->app->bind($model, fn ($app) => new ($app['db']->connection()->getDriverName() === 'mongodb' ? $document : $model));
        }
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([AnalyzeAnnotationsCommand::class]);
        }

        Event::listen(AnnotationRequested::class, CaptureAnnotation::class);
        Event::listen(EvaluationReviewRequested::class, [CaptureAnnotation::class, 'handleEvaluation']);
        Event::listen(PolicyDeployed::class, [AnalyzeAnnotations::class, 'handleDeployment']);
        $this->loadViewsFrom(__DIR__.'/../../resources/views/annotations', 'toll-troll-annotations');
        View::composer('toll-troll-annotations::review', function ($view) {
            $view->with('iconDataUri', 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/images/tolltroll-icon.svg')));
            $view->with('headingFontDataUri', 'data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/fonts/outfit-variable.woff2')));
            $view->with('bodyFontDataUri', 'data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/fonts/source-sans-3-variable.woff2')));
        });
        $this->publishes([__DIR__.'/../../config/toll-troll-annotations.php' => config_path('toll-troll-annotations.php')], 'toll-troll-annotations-config');
        $migrations = $this->app['db']->connection()->getDriverName() === 'mongodb' ? 'mongodb-migrations' : 'migrations';
        $this->publishesMigrations([__DIR__.'/../../database/'.$migrations => database_path('migrations')], 'toll-troll-annotations-migrations');

        if (! $this->app->routesAreCached()) {
            Route::prefix(config('toll-troll-annotations.path'))->middleware(config('toll-troll-annotations.middleware'))->name('toll-troll-annotations.')->group(function () {
                Route::get('/', [AnnotationController::class, 'index'])->name('index');
                Route::get('/{dataset}/export/{hash}', [AnnotationController::class, 'export'])->name('export');
                Route::post('/{dataset}/advice/{analysisId}/{suggestionKey}/dismiss', [AnnotationController::class, 'dismissAdvice'])->name('advice.dismiss');
                Route::get('/{dataset}/{id?}', [AnnotationController::class, 'show'])->name('show');
                Route::post('/{dataset}/{id}', [AnnotationController::class, 'store'])->name('store');
            });
        }
    }
}
