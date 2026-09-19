<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the response language: ?lang= → Accept-Language → the user's saved locale → fa.
 * Supported: fa (Persian, RTL), en (English), tr (Turkish).
 */
class SetLocale
{
    public const SUPPORTED = ['fa', 'en', 'tr'];

    public function handle(Request $request, Closure $next): Response
    {
        $candidates = [
            $request->query('lang'),
            $request->getPreferredLanguage(self::SUPPORTED),
            $request->user()?->locale,
        ];
        foreach ($candidates as $c) {
            $c = $c ? strtolower(substr((string) $c, 0, 2)) : null;
            if ($c && in_array($c, self::SUPPORTED, true)) {
                app()->setLocale($c);
                break;
            }
        }
        $response = $next($request);
        $response->headers->set('Content-Language', app()->getLocale());

        return $response;
    }
}
