<?php

namespace FaridM\StepChat\Laravel;

use Illuminate\View\Component;

/**
 * <x-stepchat-widget flow="lead" lang="fa" />
 */
class WidgetComponent extends Component
{
    /** @var string|null */
    public $flow;

    /** @var array<string, mixed> */
    public $options;

    /**
     * @param array<string, mixed> $options
     */
    public function __construct(?string $flow = null, array $options = [], ?string $lang = null, ?string $theme = null, ?string $color = null, ?string $title = null)
    {
        $this->flow = $flow;
        $this->options = array_filter(
            ['lang' => $lang, 'theme' => $theme, 'color' => $color, 'title' => $title] + $options,
            static function ($v) {
                return $v !== null;
            }
        );
    }

    public function render()
    {
        return function () {
            return app(WidgetRenderer::class)->render($this->flow, $this->options)->toHtml();
        };
    }
}
