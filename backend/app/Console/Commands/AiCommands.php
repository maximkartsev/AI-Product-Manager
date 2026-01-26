<?php

namespace App\Console\Commands;

use App\AI\AgentCommandDefinitionProvider;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class AiCommands extends Command implements AgentCommandDefinitionProvider
{
    protected $signature = 'ai:commands {--json : Output machine-readable JSON}';

    protected $description = 'List the curated AI agent command registry.';

    public static function getAgentCommandDefinition(): array
    {
        return [
            'name' => 'ai:commands',
            'category' => 'ai-agent',
            'purpose' => 'List the curated AI agent command registry.',
            'usage' => 'php artisan ai:commands {--json}',
            'notes' => [
                'Use --json for machine-readable output.',
                'Extend the registry by editing backend/config/ai_agent.php or by implementing AgentCommandDefinitionProvider on a command class.',
            ],
        ];
    }

    public function handle(): int
    {
        $commands = (array) config('ai_agent.commands', []);

        // Auto-discover AI-command definitions from commands implementing AgentCommandDefinitionProvider.
        $commandsDir = app_path('Console/Commands');
        if (is_dir($commandsDir)) {
            $files = File::allFiles($commandsDir);
            foreach ($files as $file) {
                $base = $file->getBasename('.php');
                if (empty($base)) {
                    continue;
                }

                $class = "App\\Console\\Commands\\{$base}";
                if (!class_exists($class) || !is_subclass_of($class, AgentCommandDefinitionProvider::class)) {
                    continue;
                }

                /** @var class-string<AgentCommandDefinitionProvider> $class */
                $def = $class::getAgentCommandDefinition();
                if (is_array($def) && !empty($def['name'])) {
                    $commands[] = $def;
                }
            }
        }

        // De-duplicate by command name.
        $byName = [];
        foreach ($commands as $c) {
            if (!is_array($c) || empty($c['name'])) {
                continue;
            }
            $byName[$c['name']] = $c;
        }
        $commands = array_values($byName);

        if ((bool) $this->option('json')) {
            $this->line(json_encode([
                'generated_at' => now()->toIso8601String(),
                'count' => count($commands),
                'commands' => $commands,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        if (empty($commands)) {
            $this->warn('No commands registered in config/ai_agent.php');
            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Category', 'Purpose', 'Usage'],
            array_map(static function ($c) {
                return [
                    $c['name'] ?? '',
                    $c['category'] ?? '',
                    $c['purpose'] ?? '',
                    $c['usage'] ?? '',
                ];
            }, $commands)
        );

        $this->line('');
        $this->line('Extend this list in backend/config/ai_agent.php');

        return self::SUCCESS;
    }
}

