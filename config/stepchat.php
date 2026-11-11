<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Max turns per conversation
    |--------------------------------------------------------------------------
    |
    | Safety valve passed to FlowEngine. A session that reaches this many
    | turns ends (status "ended") instead of continuing.
    |
    */
    'max_turns' => 200,

    /*
    |--------------------------------------------------------------------------
    | Engine extensions (all optional)
    |--------------------------------------------------------------------------
    |
    | Class names resolved through the container and handed to the engine.
    |   messages              FaridM\StepChat\Contracts\Messages            (translate/reword every engine string)
    |   interpreter           ConversationInterpreter or TurnInterpreter     (optional AI helper)
    |   change_detector       FaridM\StepChat\Contracts\ChangeDetector      (deterministic "go back" detection)
    |   handoff               FaridM\StepChat\Contracts\HandoffPolicy
    |   interpretation_policy FaridM\StepChat\Contracts\InterpretationPolicy
    |   listener              FaridM\StepChat\ConversationListener subclass
    |
    */
    'messages' => null,
    'interpreter' => null,
    'change_detector' => null,
    'handoff' => null,
    'interpretation_policy' => null,
    'listener' => null,
    'check_invariants' => true,

    /*
    |--------------------------------------------------------------------------
    | Session storage
    |--------------------------------------------------------------------------
    |
    | The widget keeps conversations in your Laravel cache so they survive a
    | page reload. `cache_store` is any store from config/cache.php (null =
    | your default store). `session_ttl` is how many minutes an idle
    | conversation is remembered.
    |
    */
    'cache_store' => null,
    'session_ttl' => 1440,

    /*
    |--------------------------------------------------------------------------
    | Browser chat widget (optional)
    |--------------------------------------------------------------------------
    |
    | Off by default. Set STEPCHAT_WIDGET=true, register at least one flow
    | below, then put @stepChatWidget in your layout. That's the whole install.
    |
    */
    'widget' => [
        'enabled' => env('STEPCHAT_WIDGET', false),

        // JSON endpoint the widget talks to: POST /{route_prefix}/{flow}
        'route_prefix' => 'chat-flow',
        'middleware' => ['web', 'throttle:60,1'],

        // name => class implementing FaridM\StepChat\Contracts\FlowFactory
        'flows' => [
            // 'lead' => App\Chat\LeadFlow::class,
        ],
        'default_flow' => 'lead',

        // Presentation. Every key can be overridden per call: @stepChatWidget('lead', ['lang' => 'fa'])
        'lang' => 'auto',          // "auto" (visitor's browser language), or any code: en, fa, ar, he, ur...
        'dir' => 'auto',           // "auto" (RTL detected from the language), "ltr" or "rtl"
        'theme' => 'auto',         // auto | light | dark
        'position' => 'end',       // end (bottom-right in LTR) | start
        'color' => null,           // brand colour, e.g. "#0ea5e9"
        'title' => null,           // header title; null uses the translated default
        'open' => false,           // start with the panel open
        'language_switcher' => false,
        'languages' => null,       // e.g. ['en', 'fa', 'ar'] to limit the switcher

        // Serve the script from here instead of the bundled copy / CDN (e.g. your own CDN).
        'asset_url' => null,
    ],
];
