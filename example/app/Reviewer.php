<?php

namespace Demo;

use Closure;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;

class Reviewer
{
    public function handle(Request $request, Closure $next)
    {
        // No login: this app accepts synthetic data only and must never be deployed.
        auth()->setUser(new GenericUser(['id' => 'demo-reviewer']));

        return $next($request);
    }
}
