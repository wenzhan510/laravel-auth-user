<?php
namespace App\Observers;

use Cache;
use App\User;

/**
 * User observer
 */
class UserObserver
{
    public function updated(User $user) // whenever there's update of user, renew cached instance
    {
        Cache::put("cachedUser.{$user->id}", $user, 30);
    }
}
