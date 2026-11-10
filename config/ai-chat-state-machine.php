<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Max turns per conversation
    |--------------------------------------------------------------------------
    |
    | Safety valve passed to FlowEngine's constructor. A session that hits
    | this many turns gets an "invalid" result instead of continuing.
    |
    */
    'max_turns' => 200,
];
