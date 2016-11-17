<?php


namespace CImrie\ODM;


use Doctrine\Common\Persistence\ManagerRegistry;
use Doctrine\Common\Proxy\Autoloader;
use Doctrine\ODM\MongoDB\DocumentManager;
use Doctrine\ODM\MongoDB\Tools\Console\Command\GenerateHydratorsCommand;
use Illuminate\Support\Collection;
use Illuminate\Support\ServiceProvider;
use CImrie\ODM\Common\Config;
use CImrie\ODM\Common\ConfigurationFactory;
use CImrie\ODM\Configuration\ODMConfigurationFactory;
use CImrie\ODM\Laravel\Console\ClearMetadataCommand;
use CImrie\ODM\Laravel\Console\CreateSchemaCommand;
use CImrie\ODM\Laravel\Console\DropSchemaCommand;
use CImrie\ODM\Laravel\Console\GenerateDocumentsCommand;
use CImrie\ODM\Laravel\Console\GenerateProxiesCommand;
use CImrie\ODM\Laravel\Console\GenerateRepositoriesCommand;
use CImrie\ODM\Laravel\Console\QueryCommand;
use CImrie\ODM\Laravel\Console\ShardSchemaCommand;
use CImrie\ODM\Laravel\Console\UpdateSchemaCommand;

class ODMServiceProvider extends ServiceProvider {

	/**
	 * Boot service provider.
	 */
	public function boot()
	{
		if (!$this->isLumen()) {
			$this->publishes([
				$this->getConfigPath() => config_path('odm.php'),
			], 'config');
		}
	}

	/**
	 * Register the service provider.
	 *
	 * @return void
	 */
	public function register()
	{
		$this->mergeConfig();
		//instantiate the registry
		//foreach, add the manager to the registry
		$this->registerManagerRegistry();
		//bind the document manager to the default (from the registry)
		$this->registerDefaultDocumentManager();
		//register the entity factory (not essential)
		$this->registerEntityFactory();
		//autoload proxies and hydrators
		$this->registerAutoloader();
		$this->registerConsoleCommands();
	}

	public function registerManagerRegistry()
	{
		$this->app->bind(ConfigurationFactory::class, ODMConfigurationFactory::class);
		$this->app->singleton('dm-registry', function ($app) {
			$registry = new IlluminateRegistry($app, $app->make(DocumentManagerFactory::class));

			// Add all managers into the registry
			foreach ($app->make('config')->get('odm.managers', []) as $manager => $managerSettings) {
				$connection = $app->make('config')->get('database.connections', []);
				$globalSettings = (new Collection($app->make('config')->get('odm')))->except('odm.managers')->toArray();

				$config = new Config($managerSettings, $connection, $globalSettings);
				$registry->addManager($manager, $config);
			}

			return $registry;
		});

		$this->app->alias('dm-registry', ManagerRegistry::class);
		$this->app->alias('dm-registry', IlluminateRegistry::class);
	}

	public function registerDefaultDocumentManager()
	{
		// Bind the default Document Manager
		$this->app->singleton('dm', function ($app) {
			return $app->make('dm-registry')->getManager();
		});

		$this->app->alias('dm', DocumentManager::class);
	}

	/**
	 * Register the Entity factory instance in the container.
	 *
	 * @return void
	 */
	protected function registerEntityFactory()
	{
		$this->app->singleton(FakerGenerator::class, function () {
			return FakerFactory::create();
		});

		$this->app->singleton(EntityFactory::class, function ($app) {
			return DocumentManager::construct(
				$app->make(FakerGenerator::class),
				$app->make('dm-registry'),
				database_path('factories')
			);
		});
	}

	/**
	 * Register proxy and hydrator autoloader
	 *
	 * @return void
	 */
	public function registerAutoloader()
	{
		$this->app->afterResolving('dm-registry', function (ManagerRegistry $registry) {
			/** @var DocumentManager $manager */
			foreach ($registry->getManagers() as $manager) {
				Autoloader::register(
					$manager->getConfiguration()->getProxyDir(),
					$manager->getConfiguration()->getProxyNamespace()
				);
			}
		});
	}

	public function registerConsoleCommands()
	{
		$this->commands([
			ClearMetadataCommand::class,
			QueryCommand::class,
			GenerateDocumentsCommand::class,
			GenerateHydratorsCommand::class,
			GenerateProxiesCommand::class,
			GenerateRepositoriesCommand::class,
			CreateSchemaCommand::class,
			DropSchemaCommand::class,
			UpdateSchemaCommand::class,
			ShardSchemaCommand::class,
		]);
	}

	/**
	 * @return bool
	 */
	protected function isLumen()
	{
		return str_contains($this->app->version(), 'Lumen');
	}

	/**
	 * Merge config
	 */
	protected function mergeConfig()
	{
		$this->mergeConfigFrom(
			$this->getConfigPath(), 'odm'
		);

		if ($this->isLumen()) {
			$this->app->configure('cache');
			$this->app->configure('database');
			$this->app->configure('odm');
		}
	}

	/**
	 * @return string
	 */
	protected function getConfigPath()
	{
		return __DIR__ . '/../config/odm.php';
	}
}