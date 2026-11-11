<?php

namespace FaridM\AiChatStateMachine\Laravel;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\HtmlString;

/**
 * Builds the one-line <script> tag for the widget from config + per-call options.
 */
class WidgetRenderer
{
    public const VERSION = '1.0.0';

    /** @var Repository */
    private $config;

    public function __construct(Repository $config)
    {
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $options overrides for config('ai-chat-state-machine.widget.*')
     */
    public function render(?string $flow = null, array $options = []): HtmlString
    {
        $cfg = (array) $this->config->get('ai-chat-state-machine.widget', []);
        if (empty($cfg['enabled'])) {
            return new HtmlString('');
        }

        $o = array_merge($cfg, $options);
        $flow = $flow ?: (string) ($o['default_flow'] ?? '');
        $endpoint = url('/' . trim((string) $o['route_prefix'], '/') . '/' . rawurlencode($flow));

        $languages = $o['languages'] ?? null;
        $attrs = [
            'src' => $this->scriptUrl($o),
            'data-endpoint' => $endpoint,
            'data-flow' => $flow,
            'data-lang' => $o['lang'] ?? 'auto',
            'data-dir' => $o['dir'] ?? 'auto',
            'data-theme' => $o['theme'] ?? 'auto',
            'data-position' => $o['position'] ?? 'end',
            'data-color' => $o['color'] ?? null,
            'data-title' => $o['title'] ?? null,
            'data-open' => !empty($o['open']) ? 'true' : null,
            'data-languages' => is_array($languages) ? implode(',', $languages) : null,
            'data-csrf' => function_exists('csrf_token') ? csrf_token() : null,
        ];

        $html = '';
        foreach ($attrs as $name => $value) {
            if ($value !== null && $value !== '') {
                $html .= ' ' . $name . '="' . e((string) $value) . '"';
            }
        }
        if (!empty($o['language_switcher']) && !is_array($languages)) {
            $html .= ' data-language-switcher';
        }

        return new HtmlString('<script' . $html . ' defer></script>');
    }

    /**
     * @param array<string, mixed> $o
     */
    private function scriptUrl(array $o): string
    {
        if (!empty($o['asset_url'])) {
            return (string) $o['asset_url'];
        }
        if (function_exists('public_path') && is_file(public_path('vendor/ai-chat-state-machine/ai-chat-widget.js'))) {
            return asset('vendor/ai-chat-state-machine/ai-chat-widget.js');
        }

        return 'https://cdn.jsdelivr.net/gh/faridmasoum/stepchat@v' . self::VERSION . '/resources/dist/ai-chat-widget.js';
    }
}
