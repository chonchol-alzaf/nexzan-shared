<?php

use Illuminate\Support\Facades\Route;
use Microservice\Auth\Middleware\KeyAuthMiddleware;
use App\Http\Controllers\MicroService\Team\TeamUserController;

Route::group(['middleware' => [KeyAuthMiddleware::class]], function () {

    // form billing service
    Route::post('/team-status', [TeamUserController::class, 'updateTeamStatus']);
   
});
