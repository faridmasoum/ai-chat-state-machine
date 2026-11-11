<?php

use FaridM\StepChat\Laravel\ChatController;
use Illuminate\Support\Facades\Route;

Route::post('{flow}', ChatController::class)
    ->where('flow', '[A-Za-z0-9_-]+')
    ->name('stepchat.turn');
