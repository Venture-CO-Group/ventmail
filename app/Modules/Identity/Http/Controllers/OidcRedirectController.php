<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Services\OidcClient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class OidcRedirectController
{
    public function __invoke(Request $request, OidcClient $client): RedirectResponse
    {
        return redirect()->away($client->authorizationUrl($request));
    }
}
