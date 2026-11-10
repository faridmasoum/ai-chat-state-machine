<?php

use FaridM\AiChatStateMachine\Laravel\ChatController;
use Illuminate\Support\Facades\Route;

Route::post('{flow}', ChatController::class)
    ->where('flow', '[A-Za-z0-9_-]+')
    ->name('ai-chat.turn');
