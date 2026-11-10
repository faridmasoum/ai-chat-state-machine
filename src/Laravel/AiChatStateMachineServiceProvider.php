<?php

namespace FaridM\AiChatStateMachine\Laravel;

use FaridM\AiChatStateMachine\Contracts\FlowFactory;
use FaridM\AiChatStateMachine\Contracts\SessionStore;
use FaridM\AiChatStateMachine\Flow;
use FaridM\AiChatStateMachine\Http\ChatEndpoint;
use FaridM\AiChatStateMachine\Http\FlowRegistry;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Optional glue for Laravel 8 through 12. The core package has no dependency
 * on Illuminate; everything here (config, widget endpoint, Blade directive)
 * is opt-in, and the widget is disabled until AI_CHAT_WIDGET=true.
 */
class AiChatStateMachineServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/ai-chat-state-machine.php', 'ai-chat-state-machine');

        $this->app->singleton(SessionStore::class, static function (Application $app): SessionStore {
            $cfg = $app['config'];

            return new CacheSessionStore(
                $app['cache']->store($cfg->get('ai-chat-state-machine.cache_store')),
                (int) $cfg->get('ai-chat-state-machine.session_ttl', 1440)
            );
        });

        $this->app->singleton(FlowRegistry::class, static function (Application $app): FlowRegistry {
            $registry = new FlowRegistry();
            $flows = (array) $app['config']->get('ai-chat-state-machine.widget.flows', []);

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
                (int) $app['config']->get('ai-chat-state-machine.max_turns', 200)
            );
        });

        $this->app->singleton(WidgetRenderer::class, static function (Application $app): WidgetRenderer {
            return new WidgetRenderer($app['config']);
        });
    }

    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/ai-chat-state-machine.php' => function_exists('config_path')
                ? config_path('ai-chat-state-machine.php')
                : base_path('config/ai-chat-state-machine.php'),
        ], 'ai-chat-state-machine-config');

        $this->publishes([
            __DIR__ . '/../../resources/dist/ai-chat-widget.js' => public_path('vendor/ai-chat-state-machine/ai-chat-widget.js'),
        ], 'ai-chat-state-machine-assets');

        if ($this->app->runningInConsole()) {
            $this->commands([InstallCommand::class]);
        }

        // Blade helpers are always registered (they render nothing while the widget is disabled).
        Blade::directive('aiChatWidget', static function (string $expression): string {
            $expression = trim($expression) === '' ? 'null' : $expression;

            return '<?php echo app(\\' . WidgetRenderer::class . '::class)->render(' . $expression . '); ?>';
        });
        Blade::component('ai-chat-widget', WidgetComponent::class);

        if ($this->app['config']->get('ai-chat-state-machine.widget.enabled')) {
            Route::prefix(trim((string) $this->app['config']->get('ai-chat-state-machine.widget.route_prefix', 'chat-flow'), '/'))
                ->middleware((array) $this->app['config']->get('ai-chat-state-machine.widget.middleware', ['web']))
                ->group(__DIR__ . '/../../routes/ai-chat.php');
        }
    }
}
