<?php

declare(strict_types=1);

namespace VehicleData\Core\Providers;

use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use VehicleData\Core\Auth\DatabaseClientResolver;
use VehicleData\Core\Console\CacheGcCommand;
use VehicleData\Core\Console\ClientCommand;
use VehicleData\Core\Console\ExamplesCommand;
use VehicleData\Core\Console\ImportCommand;
use VehicleData\Core\Console\LogsCommand;
use VehicleData\Core\Console\SourcesCommand;
use VehicleData\Core\Console\StatusCommand;
use VehicleData\Core\Console\UsageCommand;
use VehicleData\Core\Contracts\ClientResolver;
use VehicleData\Core\Http\Resources\Enrichers;
use VehicleData\Core\Importers\ImportPipeline;
use VehicleData\Core\Importers\SourceRegistry;
use VehicleData\Core\Importers\Sources\EeaSource;
use VehicleData\Core\Importers\Sources\RoFleetSource;
use VehicleData\Core\Importers\Sources\WikidataSource;
use VehicleData\Core\Importers\Sources\WmiSource;
use VehicleData\Core\Importers\Writers\FleetWriter;
use VehicleData\Core\Importers\Writers\ManufacturerWriter;
use VehicleData\Core\Importers\Writers\VariantWriter;
use VehicleData\Core\Importers\Writers\WmiWriter;
use VehicleData\Core\Kinds\KindRegistry;
use VehicleData\Core\Kinds\OpenApiAssembler;
use VehicleData\Core\Kinds\Schemas\CarSpecificationSchema;
use VehicleData\Core\Locale\LabelResolver;
use VehicleData\Core\Models;
use VehicleData\Core\Support\PackagePaths;

final class CoreServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../../config/core.php', 'core');

        $this->app->bind(ClientResolver::class, DatabaseClientResolver::class);

        $this->app->singleton(LabelResolver::class, fn () => new LabelResolver(array_values(array_map('strval', (array) config('core.locales'))), (string) config('core.default_locale')));

        $this->app->singleton(Enrichers::class, fn ($app) => new Enrichers($app->tagged('core.enrichers')));

        $this->app->singleton(KindRegistry::class, function (): KindRegistry {
            $registry = new KindRegistry;
            $registry->register(new CarSpecificationSchema);

            return $registry;
        });

        $this->app->singleton(OpenApiAssembler::class, fn ($app) => new OpenApiAssembler($app->make(KindRegistry::class), PackagePaths::openApi(), PackagePaths::examplesFragment()));

        $this->app->singleton(SourceRegistry::class, fn ($app) => new SourceRegistry([
            'eea' => new EeaSource,
            'ro-fleet' => new RoFleetSource,
            'wikidata' => new WikidataSource,
            'wmi' => new WmiSource,
        ]));

        $this->app->singleton(ImportPipeline::class, fn ($app) => new ImportPipeline([
            'variant' => new VariantWriter,
            'fleet' => new FleetWriter,
            'manufacturer' => new ManufacturerWriter,
            'wmi' => new WmiWriter,
        ]));
    }

    public function boot(): void
    {
        if ($this->app->environment('production') && (bool) config('app.debug')) {
            throw new \RuntimeException('APP_DEBUG must be false in production.');
        }

        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        $this->loadTranslationsFrom(__DIR__.'/../../resources/lang', 'core');

        $this->publishes([__DIR__.'/../../config/core.php' => config_path('core.php')], 'core-config');

        if ($this->app->runningInConsole()) {
            $this->commands([ClientCommand::class, ImportCommand::class, SourcesCommand::class, UsageCommand::class, StatusCommand::class, LogsCommand::class, CacheGcCommand::class, ExamplesCommand::class]);
        }

        Relation::enforceMorphMap([
            'manufacturer' => Models\Manufacturer::class,
            'make' => Models\Make::class,
            'model' => Models\VehicleModel::class,
            'variant' => Models\Variant::class,
            'wmi' => Models\Wmi::class,
        ]);
    }
}
