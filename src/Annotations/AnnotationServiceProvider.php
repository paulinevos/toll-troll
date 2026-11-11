<?php

namespace Vos\TollTroll\Annotations;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AnnotationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/toll-troll-annotations.php', 'toll-troll-annotations');
    }

    public function boot(): void
    {
        Event::listen(AnnotationRequested::class, CaptureAnnotation::class);
        $this->loadViewsFrom(__DIR__.'/../../resources/views/annotations', 'toll-troll-annotations');
        View::composer('toll-troll-annotations::review', function ($view) {
            $view->with('iconDataUri', 'data:image/svg+xml;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/images/tolltroll-icon.svg')));
            $view->with('headingFontDataUri', 'data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/fonts/outfit-variable.woff2')));
            $view->with('bodyFontDataUri', 'data:font/woff2;base64,'.base64_encode(file_get_contents(__DIR__.'/../../resources/fonts/source-sans-3-variable.woff2')));
        });
        $this->publishes([__DIR__.'/../../config/toll-troll-annotations.php' => config_path('toll-troll-annotations.php')], 'toll-troll-annotations-config');
        $this->publishesMigrations([__DIR__.'/../../database/migrations' => database_path('migrations')], 'toll-troll-annotations-migrations');

        if (! $this->app->routesAreCached()) {
            Route::prefix(config('toll-troll-annotations.path'))->middleware(config('toll-troll-annotations.middleware'))->name('toll-troll-annotations.')->group(function () {
                Route::get('/', [AnnotationController::class, 'index'])->name('index');
                Route::get('/{dataset}/export/{hash}', [AnnotationController::class, 'export'])->name('export');
                Route::get('/{dataset}/{id?}', [AnnotationController::class, 'show'])->name('show');
                Route::post('/{dataset}/{id}', [AnnotationController::class, 'store'])->name('store');
            });
        }
    }
}
