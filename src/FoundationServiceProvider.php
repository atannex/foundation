<?php

namespace Atannex\Foundation;

use Atannex\Foundation\Commands\GenerateFoundationCodes;
use Atannex\Foundation\Commands\GenerateFoundationSlugPaths;
use Atannex\Foundation\Commands\GenerateFoundationSlugs;
use Atannex\Foundation\Contracts\ValueResolver;
use Atannex\Foundation\Support\Resolvers\DotValueResolver;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FoundationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('atannex-foundation')
            ->hasConfigFile('foundation')
            ->hasCommands([
                GenerateFoundationCodes::class,
                GenerateFoundationSlugs::class,
                GenerateFoundationSlugPaths::class,
            ]);
    }

    public function packageRegistered(): void
    {
        /*
        |--------------------------------------------------------------------------
        | CORE SERVICES
        |--------------------------------------------------------------------------
        */

        $this->app->singleton(FoundationManager::class, function (): FoundationManager {
            return new FoundationManager;
        });

        $this->app->alias(FoundationManager::class, 'atannex');

        /*
        |--------------------------------------------------------------------------
        | VALUE RESOLVER (CRITICAL BINDING)
        |--------------------------------------------------------------------------
        */

        $this->app->singleton(ValueResolver::class, DotValueResolver::class);

        /*
        |--------------------------------------------------------------------------
        | HELPERS
        |--------------------------------------------------------------------------
        */

        if (file_exists(__DIR__ . '/../helpers.php')) {
            require_once __DIR__ . '/../helpers.php';
        }
    }
}
