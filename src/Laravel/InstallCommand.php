<?php

namespace FaridM\AiChatStateMachine\Laravel;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * php artisan ai-chat:install: publishes config + widget script and scaffolds a sample flow.
 */
class InstallCommand extends Command
{
    protected $signature = 'ai-chat:install {--force : Overwrite existing files}';

    protected $description = 'Publish the config and widget script, and scaffold an example flow';

    public function handle(Filesystem $files): int
    {
        $force = (bool) $this->option('force');
        $this->call('vendor:publish', ['--tag' => 'ai-chat-state-machine-config', '--force' => $force]);
        $this->call('vendor:publish', ['--tag' => 'ai-chat-state-machine-assets', '--force' => true]);

        $target = app_path('Chat/LeadFlow.php');
        if ($files->exists($target) && !$force) {
            $this->warn('app/Chat/LeadFlow.php already exists (use --force to overwrite).');
        } else {
            $files->ensureDirectoryExists(dirname($target));
            $files->copy(__DIR__ . '/../../resources/stubs/LeadFlow.stub', $target);
            $this->info('Created app/Chat/LeadFlow.php');
        }

        $this->newLine();
        $this->line('Next steps:');
        $this->line('  1. Register the flow in config/ai-chat-state-machine.php:  widget.flows => [\'lead\' => App\\Chat\\LeadFlow::class]');
        $this->line('  2. Add  AI_CHAT_WIDGET=true  to your .env');
        $this->line('  3. Put  @aiChatWidget  just before </body> in your layout');

        return self::SUCCESS;
    }
}
