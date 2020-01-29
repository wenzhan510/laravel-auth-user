<?php

namespace App\Helpers;

use Cache;
use App\User;
use Auth;

class CacheUser{ //cache-user class
    public static function user($id){
        if(!$id||$id<=0||!is_numeric($id)){return;} // if $id is not a reasonable integer, return false instead of checking users table

        return Cache::remember('cachedUser.'.$id, 30, function() use($id) {
            return User::find($id); // cache user instance for 30 minutes
        });
    }
}
