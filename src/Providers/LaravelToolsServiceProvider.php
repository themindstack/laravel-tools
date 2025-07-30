<?php
namespace Quangphuc\LaravelTools\Providers;

use Illuminate\Support\ServiceProvider;
use Quangphuc\LaravelTools\Commands\Model\ModelGenerateProperties;

class LaravelToolsServiceProvider extends ServiceProvider
{
    public function register()
    {
    }

    public function boot()
    {
        $this->commands([
            ModelGenerateProperties::class,
        ]);
    }
}
