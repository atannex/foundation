<?php

namespace Atannex\Foundation;

use Atannex\Foundation\Commands\FoundationCommand;
use Atannex\Foundation\FoundationManager;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class FoundationServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('atannex-foundation')
            ->hasConfigFile('foundation')
            ->hasCommand(FoundationCommand::class);
    }

    public function packageRegistered(): void
    {
        $this->app->singleton(FoundationManager::class, function (): FoundationManager {
            return new FoundationManager();
        });

        $this->app->alias(FoundationManager::class, 'atannex');

        if (file_exists(__DIR__ . '/../helpers.php')) {
            require_once __DIR__ . '/../helpers.php';
        }
    }
}
