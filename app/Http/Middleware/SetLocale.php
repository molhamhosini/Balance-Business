<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SetLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->session()->get('locale') ?? $request->user()?->locale ?? config('app.locale');
        if (in_array($locale, ['ar', 'en'], true)) {
            app()->setLocale($locale);
        }

        return $next($request);
    }
}
