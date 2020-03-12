<?php
namespace App\Observers;

use Cache;
use App\User;

/**
 * User observer
 */
class UserObserver
{
    public function saved(User $user) // whenever there's update or create of user, renew cached instance
    {
        Cache::forget("cachedUser.{$user->id}");
    }
}
