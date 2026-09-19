<?php

namespace App\Http\Middleware;

use App\Services\AnalysisServiceClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyAnalysisSignature
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! AnalysisServiceClient::verifySignature($request->getContent(), $request->header('X-Ravan-Signature'))) {
            abort(401, 'invalid signature');
        }

        return $next($request);
    }
}
