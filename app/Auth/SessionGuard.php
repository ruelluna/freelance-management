<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use Lab404\Impersonate\Guard\SessionGuard as ImpersonateSessionGuard;

class SessionGuard extends ImpersonateSessionGuard
{
    public function setUser(Authenticatable $user): static
    {
        // Laravel 13 no longer clears this flag in setUser. Impersonation
        // logs out quietly first, so without this the guard stays logged out.
        $this->loggedOut = false;

        return parent::setUser($user);
    }
}
