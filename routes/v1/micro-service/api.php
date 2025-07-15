<?php

use Illuminate\Support\Facades\Route;
use Microservice\Auth\Middleware\KeyAuthMiddleware;
use Nexzan\Shared\Http\Controllers\MicroService\Team\TeamController;

Route::group(['middleware' => [KeyAuthMiddleware::class]], function () {

    // form billing service
    Route::post('/team-status', [TeamController::class, 'updateTeamStatus']);
   
});
