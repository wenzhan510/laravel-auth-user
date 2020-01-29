# Laravel optimization tutorial: Cache User Model in Auth

# [中文教程-在Auth中用Cache调度缓存的User模型](#中文教程-在Auth中用Cache调度缓存的User模型)

## Abstract

After our project deployed online, we noticed that every request queried the users table in the database. When the users table get larger and larger, this becomes a key step for the performance.

This tutorial presents a way to cache the eloquent user model in redis, observe its update and return this model whenever the Auth Facade is used in an laravel application. Therefore, the total query number significantly reduced. This tutorial also included how to apply it to Passport setting.

In our production environment, this method really helps.

## Code Source
[https://github.com/lyn510/laravel-auth-user](https://github.com/lyn510/laravel-auth-user)

## Index
- [Install an empty Laravel Project 5.7, add on auth, cache, database](#Step-1-Install-an-empty-Laravel-5-7).
- [add Cache-User](#Step-2-Add-Cache-User)
- [add Passport](#Step-3-Add-Passport)

## Prerequisite
- composer
- mysql
- redis
- other basics of laravel

## Introduction

Cache user to improve performance is not a new idea. However, when I dig for tutorial for Laravel auth, it is rare to find one. One of the most helpful tutorial is by Pauland (https://paulund.co.uk/laravel-cache-authuser), which is very helpful but missed a key part: it did not include fetching cached model from token, which is the most common scenario.

This step to step tutorial is an extension based on Pauland's inspring one, using a freshly installed Laravel 5.7 project as example. Personally, I believe it can be applied to later version Laravel as well.

We've used it in our production environment and it worked pretty good.

## Main Tutorial
### Step-1-Install-an-empty-Laravel-5-7
Install a fresh Laravel Project 5.7, add on auth, cache, database
```
$ composer create-project --prefer-dist laravel/laravel laravel-auth-user "5.7.*"
```
install auth
```
$ php artisan make:auth
```
connect to your local mysql
locally create a mysql database and name it `laravel-auth-user`. Edit `.env` to allow laravel connect to it
```
...
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel-cache-auth
DB_USERNAME=root
DB_PASSWORD=
...
```
migrate your database
```
$ php artisan migrate
```
Serve your program. Visit your current project and you shall be able to create a new user by register, and login.

![laravel-default-login-page](https://github.com/lyn510/laravel-auth-user/blob/master/readme_pictures/laravel%E9%BB%98%E8%AE%A4%E7%99%BB%E9%99%86%E7%95%8C%E9%9D%A2.png?raw=true)

Install laravel-debuglar to observe database query counts during current visit.
 *laravel-debuglar is a powerful tool to optimize your website performance. Please remember to install it only in dev environment.*

```
$ composer require barryvdh/laravel-debugbar --dev
```
After install, you will see a red debug bar at the bottom of your page. When a user is logged in, the database query number will appear to be 1. And you can see how much time it used to carry out the query. Is usually pretty fast on a freshly installed laravel project, yet it may grow incredibly slower once the users table get large.

![laravel-debuglar-shows-query-number-1-when-logged-in](https://github.com/lyn510/laravel-auth-user/blob/master/readme_pictures/laravel%20debuglar%E6%98%BE%E7%A4%BA%EF%BC%8C%E7%99%BB%E9%99%86%E5%90%8E%E6%95%B0%E6%8D%AE%E5%BA%93query%E6%95%B0%E4%B8%BA1.png?raw=true)

install predis

```
$ composer require predis/predis
```
modify `.env` for setting up redis
```
...
CACHE_DRIVER=redis
CACHE_PREFIX=laravel-cache-user
...
```
check redis connectivity with tinker
```
$ php artisan tinker
```
store any random data to test redis
```
>>> Cache::put('data','success',1)
```
and see what you get back
```
>>> Cache::get('data')
```
it will show like this
```
>>> Cache::put('data','success',1)
=> null
>>> Cache::get('data')
=> "success"
>>>
```
which means your redis is succesfully connected

### Step-2-Add-Cache-User
after the previous settings, now we will cache the user model, and get the model instance when necessary.

create a file`app\Helpers\CacheUser.php`

```
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

```
Edit `config\app.php`
```
'aliases' => [
...
'CacheUser' => App\Helpers\CacheUser::class,
...
```
Then, we need to ensure that the cached User Model instance got updated once the table has changed. We use an observer to watch the User Model to do that. Create a file `app\Observers\UserObserver.php`。
```
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

```
Register the Observer so that it is called when needed.
Edit`app\Providers\AppServiceProvider.php`
```
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Observers\UserObserver;
use App\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        User::observe(UserObserver::class);
    }
}

```
Last, check **Cached User** instead of User when Auth is checking a request . Create file `app\Auth\CacheUserProvider.php`

```
<?php
namespace App\Auth;
use App\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Support\Facades\Cache;
use CacheUser;
/**
 * Class CacheUserProvider
 * @package App\Auth
 */
class CacheUserProvider extends EloquentUserProvider
{
    /**
     * CacheUserProvider constructor.
     * @param HasherContract $hasher
     */
    public function __construct(HasherContract $hasher)
    {
        parent::__construct($hasher, User::class);
    }
    /**
     * @param mixed $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveById($identifier)
    {
        return CacheUser::user($identifier);
    }

    public function retrieveByToken($identifier, $token)
    {
        $model = CacheUser::user($identifier);

        if (! $model) {
            return null;
        }

        $rememberToken = $model->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $model : null;
    }
}

```
In the above two functions, `retrieveById`is used when a guest tries to login via credentials like email and password. `retrieveByToken` is used when a logged user tries to perform a request. Both functions are overritten to ensure that the model is fetched from Cached-User instead of User.
Register the new AuthService Provider by editing `app\Providers\AuthServiceProvider.php`
```
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Auth\CacheUserProvider;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Auth::provider('cache-user', function() {
            return resolve(CacheUserProvider::class);
        });
    }
}

```
Then edit `config\auth.php`
```
...
'providers' => [
        'users' => [
            'driver' => 'cache-user', // modify to use cached user instance
            'model' => App\User::class,
        ],
...
```
Serve your project. You will see that when a user is logged in, the query number changed to 0 after the first time. But it won't affect user visit.

![laravel-debuglar-show-query-number-reduce-to-0](https://github.com/lyn510/laravel-auth-user/blob/master/readme_pictures/%E7%BC%93%E5%AD%98%E5%90%8E%EF%BC%8C%E6%95%B0%E6%8D%AE%E5%BA%93query%E6%95%B0%E4%B8%BA0.png?raw=true)

### Step-3-Add-Passport
The final part of the tutorial is exactly the same as in laravel official document, except one minor thing.
```
$ composer require laravel/passport:^7.0
```
Please note that current passport version (`8.*`) don't support laravel 5.7 anymore. Therefore please specify your passport version.
```
$ php artisan migrate
$ php artisan passport:install
```
add the Laravel\Passport\HasApiTokens trait to your App\User model
modify `app\User.php`
```
<?php

namespace App;

use Laravel\Passport\HasApiTokens;
...

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;
...
```
register api routes
edit `app\Providers\AuthServiceProvider.php`
```
<?php

namespace App\Providers;

use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Auth\CacheUserProvider;
use Illuminate\Support\Facades\Auth;
...

class AuthServiceProvider extends ServiceProvider
{

...
public function boot()
    {
        $this->registerPolicies();

        Auth::provider('cache-user', function() {
            return resolve(CacheUserProvider::class);
        });

        Passport::routes();
    }

```

change default auth method
edit file `config\auth.php`
```
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
```
That's it. We are still exploring cache user usage in passport API setting.

## Reference
- laravel official document (v
5.7): [https://laravel.com/docs/5.7](https://laravel.com/docs/5.7)
- Pauland's tutorial on cache user: [https://paulund.co.uk/laravel-cache-authuser](https://paulund.co.uk/laravel-cache-authuser




# 中文教程-在Auth中用Cache调度缓存的User模型

## 源代码地址
[https://github.com/lyn510/laravel-auth-user](https://github.com/lyn510/laravel-auth-user)

## 摘要

在laravel系统上线运行后我们发现，用户的每一次访问，都需要向数据库请求，验证其身份。对于用户较多的线上程序，频繁访问users表，成为系统的一个性能门槛。

本文所述方法 **采用redis缓存auth访问所需的Eloquent User Model，又采取observer跟踪更新这个model** ，减少在登陆时对模型的频繁访问。本教程也含对passport API适配的情况。

实践验证，这能显著减少数据库负担，降低系统运维成本，改善用户访问体验。


## 内容目录：
- [在空白laravel5.7程序里，配置auth、cache、database等基本内容](#在空白laravel5.7程序里，配置auth、cache、database等基本内容)
- [在上述工程基础上，增加Cache-User的具体办法](#在上述工程基础上，增加Cache-User的具体办法)
- [如何将它适配Passport](#如何将它适配Passport)

## 阅读前需求
- 本教程默认用户已安装composer
- 本教程默认用户已为本地环境配置mysql
- 本教程默认用户已为本地环境配置redis，包括配置predis和安装redis-cli。
- 本教程默认用户已有基础的laravel经验，知道如何本地serve程序，怎样在页面中打开自己的laravel工程。


## 前言

随着网站用户的增加，运维负担也不断加大。因为laravel默认的auth方法会在每次访问时对数据库进行请求来获取user model，对users表的频繁访问成为了网站性能的一个瓶颈。

一个常用的办法，是通过cache，对user model进行缓存，避免在每次打开页面的时候，都访问一次数据库的users表格，有效减少database query。

在laravel讨论社区，对这个方法进行实践的教程非常少。一个比较有用的教程来自 https://paulund.co.uk/laravel-cache-authuser，但实践发现这个教程遗漏了通过token获取缓存model的办法，导致如果单纯依靠它，并不能在用户日常使用中成功缓存user model。

实践中，我们在上述教程的基础上做了一些延伸，增加了通过token获取缓存model的办法。另外，增加了将这个办法拓展至passport配置API后端的部分。

我们将在这个问题上获得的一些经验分享，希望给更多的开发者朋友带来帮助。

为了方便理解，本教程将带领读者在全新空白laravel工程上，一步步配置。

内容或有疏漏，恳请指正。


## 正文

### 在空白laravel5.7程序里，配置auth、cache、database等基本内容

首先安装空白laravel 5.7教程

```
$ composer create-project --prefer-dist laravel/laravel laravel-auth-user "5.7.*"
```

安装auth

```
$ php artisan make:auth
```

配置mysql

在本地新建名为`laravel-auth-user`的mysql数据库，修改`.env`文件，和本地mysql数据库进行连接：
```
...
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel-cache-auth
DB_USERNAME=root
DB_PASSWORD=
...
```
数据库迁移
```
$ php artisan migrate
```
serve程序，打开页面尝试本地注册，顺利注册，可以登陆。

![laravel默认登陆界面.png](https://github.com/lyn510/laravel-auth-user/blob/master/readme_pictures/laravel%E9%BB%98%E8%AE%A4%E7%99%BB%E9%99%86%E7%95%8C%E9%9D%A2.png?raw=true)

安装laravel-debuglar，观察访问中所进行的database query的数量。

备注：laravel-debuglar是一个非常好用的工具，可以观察到目前使用了多少个界面、访问多少次数据库，指令具体是什么，耗费时间是多少，在优化时经常使用。这个包强烈建议只安装在dev环境，否则会有泄漏敏感数据的危险。
```
$ composer require barryvdh/laravel-debugbar --dev
```
安装后，我们会发现，页面下方出现红色的debug条目，点开可以查看当前页面query数量。在登录状态下，用户访问任何界面，都会显示database query数量为1，访问了users表。目前这个访问是比较快的。但当users表增大时，这个数字会显著增加。

![laravel debuglar显示，登陆后数据库query数为1](https://github.com/lyn510/laravel-auth-user/blob/master/readme_pictures/laravel%20debuglar%E6%98%BE%E7%A4%BA%EF%BC%8C%E7%99%BB%E9%99%86%E5%90%8E%E6%95%B0%E6%8D%AE%E5%BA%93query%E6%95%B0%E4%B8%BA1.png?raw=true)

安装predis
```
$ composer require predis/predis
```
配置redis，修改`.env`文件
```
...
CACHE_DRIVER=redis
CACHE_PREFIX=laravel-cache-user
...
```
因为目前程序并没有使用cache的地方，为了直接测试是否关联成功，我们后台登陆查看。
```
$ php artisan tinker
```
在显示的界面中随便缓存一个内容
```
>>> Cache::put('data','success',1)
```
然后获取这个内容
```
>>> Cache::get('data')
```
应该会显示
```
>>> Cache::put('data','success',1)
=> null
>>> Cache::get('data')
=> "success"
>>>
```
这说明cache配置成功。

### 在上述工程基础上，增加Cache-User的具体办法
在上面的内容中，将user model进行cache。
首先，我们要将User Model缓存起来放到cache里，在需要的时候去调度它。
创建文件：`app\Helpers\CacheUser.php`

```
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

```
将这个class的简称加入列表，方便调用。修改`config\app.php`
```
'aliases' => [
...
'CacheUser' => App\Helpers\CacheUser::class,
...
```
接着，我们需要确保，每当UserModel受到修改的时候，这个缓存的模型也会同步更新，避免内容失效。这是通过建立observer来实现的。建立文件`app\Observers\UserObserver.php`。
```
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

```
将这个observer登记起来，让它自动运行。
修改`app\Providers\AppServiceProvider.php`
```
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Observers\UserObserver;
use App\User;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }

    /**
     * Bootstrap any application services.
     *
     * @return void
     */
    public function boot()
    {
        User::observe(UserObserver::class);
    }
}

```
最后，我们需要让Auth方法在检查的时候，从缓存的user而非数据库users表来获取需要的模型
新建文件`app\Auth\CacheUserProvider.php`

```
<?php
namespace App\Auth;
use App\User;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Contracts\Hashing\Hasher as HasherContract;
use Illuminate\Support\Facades\Cache;
use CacheUser;
/**
 * Class CacheUserProvider
 * @package App\Auth
 */
class CacheUserProvider extends EloquentUserProvider
{
    /**
     * CacheUserProvider constructor.
     * @param HasherContract $hasher
     */
    public function __construct(HasherContract $hasher)
    {
        parent::__construct($hasher, User::class);
    }
    /**
     * @param mixed $identifier
     * @return \Illuminate\Contracts\Auth\Authenticatable|null
     */
    public function retrieveById($identifier)
    {
        return CacheUser::user($identifier);
    }

    public function retrieveByToken($identifier, $token)
    {
        $model = CacheUser::user($identifier);

        if (! $model) {
            return null;
        }

        $rememberToken = $model->getRememberToken();

        return $rememberToken && hash_equals($rememberToken, $token) ? $model : null;
    }
}

```
上面两个方法中，`retrieveById`在用户输入用户名密码登录时调度。`retrieveByToken`在用户后续登陆时通过token比对调度。两个方法的改写，保证用户登陆使用的是被缓存的用户模型。
为了将CacheUserProvider注册到auth中进行调用，修改文件`app\Providers\AuthServiceProvider.php`
```
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Auth\CacheUserProvider;
use Illuminate\Support\Facades\Auth;

class AuthServiceProvider extends ServiceProvider
{
    /**
     * The policy mappings for the application.
     *
     * @var array
     */
    protected $policies = [
        'App\Model' => 'App\Policies\ModelPolicy',
    ];

    /**
     * Register any authentication / authorization services.
     *
     * @return void
     */
    public function boot()
    {
        $this->registerPolicies();

        Auth::provider('cache-user', function() {
            return resolve(CacheUserProvider::class);
        });
    }
}

```
接着修改`config\auth.php`
```
...
'providers' => [
        'users' => [
            'driver' => 'cache-user', // modify to use cached user instance
            'model' => App\User::class,
        ],
...
```
serve页面，登录状态下，刷新后可以发现，query数量变成0，但不影响各种访问。

![缓存后，数据库query数为0.png](https://github.com/lyn510/laravel-auth-user/blob/master/readme_pictures/%E7%BC%93%E5%AD%98%E5%90%8E%EF%BC%8C%E6%95%B0%E6%8D%AE%E5%BA%93query%E6%95%B0%E4%B8%BA0.png?raw=true)

### 如何将它适配Passport

最后讲一下如何适配passport，实际上就是普通passport适配的基本教程，参见laravel官方文档。
```
$ composer require laravel/passport:^7.0
```
这一步注意，现行passport版本不支持laravel5.7框架，安装时需指定版本号。
```
$ php artisan migrate
$ php artisan passport:install
```
按照官方教程，add the Laravel\Passport\HasApiTokens trait to your App\User model
修改`app\User.php`
```
<?php

namespace App;

use Laravel\Passport\HasApiTokens;
...

class User extends Authenticatable
{
    use HasApiTokens, Notifiable;
...
```
注册api路径
修改`app\Providers\AuthServiceProvider.php`
```
<?php

namespace App\Providers;

use Laravel\Passport\Passport;
use Illuminate\Support\Facades\Gate;
use Illuminate\Foundation\Support\Providers\AuthServiceProvider as ServiceProvider;
use App\Auth\CacheUserProvider;
use Illuminate\Support\Facades\Auth;
...

class AuthServiceProvider extends ServiceProvider
{

...
public function boot()
    {
        $this->registerPolicies();

        Auth::provider('cache-user', function() {
            return resolve(CacheUserProvider::class);
        });

        Passport::routes();
    }

```

最后，修改auth默认的方式
修改文件`config\auth.php`
```
'guards' => [
    'web' => [
        'driver' => 'session',
        'provider' => 'users',
    ],

    'api' => [
        'driver' => 'passport',
        'provider' => 'users',
    ],
],
```

关于api passport中cache user的具体使用，我们还在摸索，暂时就说到这里。


## 结果

在实际使用中，和本教程的区别在于，我们使用redis作为session driver（这部分内容的配置参考官方教程即可）。在实际使用的前后端一体系统中（使用blade界面），增加cache user方法，能显著减轻对user表的负担，减少mysql数据库的负荷。目前我们的前后端分离系统仍在开发阶段，上述配置可行，但尚未来得及实践进入passport API阶段之后实际优化效果是什么。


## 参考
- laravel 官方教程（5.7版）：https://laravel.com/docs/5.7
- 前人关于cache user的教程：https://paulund.co.uk/laravel-cache-authuser
