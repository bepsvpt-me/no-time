<?php

namespace App\Providers;

use Illuminate\Foundation\Providers\ComposerServiceProvider;
use Illuminate\Foundation\Providers\ConsoleSupportServiceProvider;

class ConsoleServiceProvider extends ConsoleSupportServiceProvider
{
    protected $providers = [
        ArtisanServiceProvider::class,
        ComposerServiceProvider::class,
    ];
}
