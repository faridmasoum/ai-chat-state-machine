<?php

namespace Farid\AiChatStateMachine\Laravel;

use Illuminate\Support\ServiceProvider;

/**
 * Optional glue for Laravel 8 through 12. The package itself has no
 * dependency on Illuminate; this provider only exists to publish the
 * config file. Nothing here is required to use FlowEngine directly.
 */
class AiChatStateMachineServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->publishes([
            __DIR__ . '/../../config/ai-chat-state-machine.php' => function_exists('config_path')
                ? config_path('ai-chat-state-machine.php')
                : base_path('config/ai-chat-state-machine.php'),
        ], 'ai-chat-state-machine-config');
    }

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/ai-chat-state-machine.php', 'ai-chat-state-machine');
    }
}
