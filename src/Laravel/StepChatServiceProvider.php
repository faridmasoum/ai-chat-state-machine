<?php

namespace FaridM\StepChat\Laravel;

use FaridM\StepChat\Contracts\FlowFactory;
use FaridM\StepChat\Contracts\SessionStore;
use FaridM\StepChat\Flow;
use FaridM\StepChat\Http\ChatEndpoint;
use FaridM\StepChat\Http\FlowRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Optional glue for Laravel 8 through 12. The core package has no dependency
 * on Illuminate; everything here (config, widget endpoint, Blade directive)
 * is opt-in, and the widget is disabled until STEPCHAT_WIDGET=true.
 */
class StepChatServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/stepchat.php', 'stepchat');

        $this->app->singleton(SessionStore::class, static function (Application $app): SessionStore {
            $cfg = $app['config'];

            return new CacheSessionStore(
                $app['cache']->store($cfg->get('stepchat.cache_store')),
                (int) $cfg->get('stepchat.session_ttl', 1440)
            );
        });

        $this->app->singleton(FlowRegistry::class, static function (Application $app): FlowRegistry {
            $registry = new FlowRegistry();
            $flows = (array) $app['config']->get('stepchat.widget.flows', []);

            foreach ($flows as $name => $factory) {
                $registry->register((string) $name, static function (?string $locale) use ($app, $name, $factory): Flow {
                    $instance = is_string($factory) ? $app->make($factory) : $factory;
                    $flow = $instance instanceof FlowFactory ? $instance->make($locale) : $instance($locale);
                    $flow->onComplete(static function (array $answers) use ($name): void {
                        event(new FlowCompleted((string) $name, $answers));
                    });

                    return $flow;
                });
            }

            return $registry;
        });

        $this->app->singleton(ChatEndpoint::class, static function (Application $app): ChatEndpoint {
            return new ChatEndpoint(
                $app->make(FlowRegistry::class),
                $app->make(SessionStore::class),
                null,
                (int) $app['config']->get('stepchat.max_turns', 200)
            );
        });

        $this->app->singleton(WidgetRenderer::class, static function (Application $app): WidgetRenderer {
            return new WidgetRenderer($app['config']);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/stepchat.php' => function_exists('config_path')
                ? config_path('stepchat.php')
                : base_path('config/stepchat.php'),
        ], 'stepchat-config');

        $this->publishes([
            __DIR__ . '/../../resources/dist/stepchat-widget.js' => public_path('vendor/stepchat/stepchat-widget.js'),
        ], 'stepchat-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        // Blade helpers are always registered (they render nothing while the widget is disabled).
        Blade::directive('stepChatWidget', static function (string $expression): string {
            $expression = trim($expression) === '' ? 'null' : $expression;

            return '<?php echo app(\\' . WidgetRenderer::class . '::class)->render(' . $expression . '); ?>';
        });
        Blade::component('stepchat-widget', WidgetComponent::class);

        if ($this->app['config']->get('stepchat.widget.enabled')) {
            Route::prefix(trim((string) $this->app['config']->get('stepchat.widget.route_prefix', 'chat-flow'), '/'))
                ->middleware((array) $this->app['config']->get('stepchat.widget.middleware', ['web']))
                ->group(__DIR__ . '/../../routes/stepchat.php');
        }
    }
}
