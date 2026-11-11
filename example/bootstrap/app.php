<?php

use Demo\DemoServiceProvider;
use Demo\Reviewer;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Middleware;
use Laravel\Ai\AiServiceProvider;
use Vos\TollTroll\Annotations\AnnotationServiceProvider;
use Vos\TollTroll\TollTrollServiceProvider;

return Application::configure(basePath: dirname(__DIR__))
    ->withProviders([AiServiceProvider::class, TollTrollServiceProvider::class, AnnotationServiceProvider::class, DemoServiceProvider::class])
    ->withRouting(web: __DIR__.'/../routes/web.php', commands: __DIR__.'/../routes/console.php')
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [Reviewer::class]);
        // Local-only demo; allow the orb's authenticated preview proxy to supply HTTPS.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions()
    ->create();
