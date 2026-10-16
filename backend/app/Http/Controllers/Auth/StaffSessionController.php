<?php

namespace App\Http\Controllers\Auth;

/** Cooperative staff (`users` table). */
class StaffSessionController extends SessionController
{
    protected function guard(): string
    {
        return 'web';
    }

    protected function otherGuard(): string
    {
        return 'platform';
    }
}
