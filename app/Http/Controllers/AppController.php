<?php

namespace App\Http\Controllers;

use App\Support\PortalContext;
use Inertia\Inertia;
use Inertia\Response;

class AppController extends Controller
{
    public function home(): Response
    {
        $portal = PortalContext::portalOrFail();

        return Inertia::render('Dashboard', [
            'stats' => [
                'portal' => $portal->domain,
                'users' => $portal->users()->count(),
                'installedAt' => $portal->installed_at?->toDateTimeString(),
                'scope' => $portal->scope ?? [],
            ],
        ]);
    }
}
