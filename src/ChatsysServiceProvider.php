<?php

namespace Chatsys;

use Chatsys\Console\InstallCommand;
use Chatsys\Console\PublishCommand;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class ChatsysServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     *
     * @return void
     */
    public function register()
    {
        app()->bind('ChatsysMessenger', function () {
            return new \Chatsys\ChatsysMessenger;
        });
    }

    /**
     * Bootstrap services.
     *
     * @return void
     */
    public function boot()
    {
        // Load Views and Routes
        $this->loadViewsFrom(__DIR__ . '/views', 'Chatsys');
        $this->loadRoutes();

        if ($this->app->runningInConsole()) {
            $this->commands([
                InstallCommand::class,
                PublishCommand::class,
            ]);
            $this->setPublishes();
        }
    }

    /**
     * Publishing the files that the user may override.
     *
     * @return void
     */
    protected function setPublishes()
    {
        // Load user's avatar folder from package's config
        $userAvatarFolder = json_decode(json_encode(include(__DIR__.'/config/Chatsys.php')))->user_avatar->folder;

        // Config
        $this->publishes([
            __DIR__ . '/config/Chatsys.php' => config_path('Chatsys.php')
        ], 'Chatsys-config');

        // Migrations
        $this->publishes([
            __DIR__ . '/database/migrations/2022_01_10_99999_add_active_status_to_users.php' => database_path('migrations/' . date('Y_m_d') . '_000000_add_active_status_to_users.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_add_avatar_to_users.php' => database_path('migrations/' . date('Y_m_d') . '_000000_add_avatar_to_users.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_add_channel_id_to_users.php' => database_path('migrations/' . date('Y_m_d') . '_000000_add_channel_id_to_users.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_add_dark_mode_to_users.php' => database_path('migrations/' . date('Y_m_d') . '_000000_add_dark_mode_to_users.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_add_messenger_color_to_users.php' => database_path('migrations/' . date('Y_m_d') . '_000000_add_messenger_color_to_users.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_create_Chatsys_channels_table.php' => database_path('migrations/' . date('Y_m_d') . '_000000_create_Chatsys_channels_table.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_create_Chatsys_favorites_table.php' => database_path('migrations/' . date('Y_m_d') . '_000000_create_Chatsys_favorites_table.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_create_Chatsys_messages_table.php' => database_path('migrations/' . date('Y_m_d') . '_000000_create_Chatsys_messages_table.php'),
            __DIR__ . '/database/migrations/2022_01_10_99999_create_Chatsys_channel_user_table.php' => database_path('migrations/' . date('Y_m_d') . '_000001_create_Chatsys_channel_user_table.php'),
        ], 'Chatsys-migrations');

        // Models
        $isV8 = explode('.', app()->version())[0] >= 8;
        $this->publishes([
            __DIR__ . '/Models' => app_path($isV8 ? 'Models' : '')
        ], 'Chatsys-models');

        // Controllers
        $this->publishes([
            __DIR__ . '/Http/Controllers' => app_path('Http/Controllers/vendor/Chatsys')
        ], 'Chatsys-controllers');

        // Views
        $this->publishes([
            __DIR__ . '/views' => resource_path('views/vendor/Chatsys')
        ], 'Chatsys-views');

        // Assets
        $this->publishes([
            // CSS
            __DIR__ . '/assets/css' => public_path('css/Chatsys'),
            // JavaScript
            __DIR__ . '/assets/js' => public_path('js/Chatsys'),
            // Images
            __DIR__ . '/assets/imgs' => storage_path('app/public/' . $userAvatarFolder),
             // CSS
             __DIR__ . '/assets/sounds' => public_path('sounds/Chatsys'),
        ], 'Chatsys-assets');
    }

    /**
     * Group the routes and set up configurations to load them.
     *
     * @return void
     */
    protected function loadRoutes()
    {
        Route::group($this->routesConfigurations(), function () {
            $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
        });
        Route::group($this->apiRoutesConfigurations(), function () {
            $this->loadRoutesFrom(__DIR__ . '/routes/api.php');
        });
    }

    /**
     * Routes configurations.
     *
     * @return array
     */
    private function routesConfigurations()
    {
        return [
            'prefix' => config('Chatsys.routes.prefix'),
            'namespace' =>  config('Chatsys.routes.namespace'),
            'middleware' => config('Chatsys.routes.middleware'),
        ];
    }
    /**
     * API routes configurations.
     *
     * @return array
     */
    private function apiRoutesConfigurations()
    {
        return [
            'prefix' => config('Chatsys.api_routes.prefix'),
            'namespace' =>  config('Chatsys.api_routes.namespace'),
            'middleware' => config('Chatsys.api_routes.middleware'),
        ];
    }
}
