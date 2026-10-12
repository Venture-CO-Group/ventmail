<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Hostonkénti robots.txt: a nyilvános termékoldal indexelhető, az app és az API nem
 * (golden rule 11: a dev/staging teljes tiltása a Caddy fejlécével együtt marad).
 */
final class RobotsController
{
    public function __invoke(Request $request): Response
    {
        $landing = strtolower($request->getHost()) === strtolower((string) config('app.landing_host'))
            && app()->environment('production');

        $body = $landing
            ? "User-agent: *\nAllow: /\n"
            : "User-agent: *\nDisallow: /\n";

        return response($body, 200, ['Content-Type' => 'text/plain; charset=UTF-8']);
    }
}
