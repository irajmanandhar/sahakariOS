<?php

namespace App\Http\Controllers\Auth;

/** SaaS operator staff (`platform_users` table). */
class PlatformSessionController extends SessionController
{
    protected function guard(): string
    {
        return 'platform';
    }

    protected function otherGuard(): string
    {
        return 'web';
    }
}
