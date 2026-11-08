<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\ChoiceController;
use App\Http\Controllers\CoupleExtrasController;
use App\Http\Controllers\LimitController;
use App\Http\Controllers\DiceController;
use App\Http\Controllers\GameController;
use App\Http\Controllers\PublicMatchController;
use App\Http\Controllers\VerifyEmailController;
use App\Http\Controllers\VodController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\CoupleController;
use App\Http\Controllers\IntersectionController;
use App\Http\Controllers\NightController;
use App\Http\Controllers\QuemController;
use Illuminate\Support\Facades\Route;

Route::get('/config', fn () => ['billing_enabled' => config('love.billing_enabled')]);

Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:10,1');
Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::get('/email/verify/{id}/{hash}', [VerifyEmailController::class, 'verify'])
    ->middleware('signed')->name('verification.verify');

Route::middleware(['auth:sanctum', 'verified.if'])->group(function () {
    // Estas rotas funcionam mesmo sem o e-mail confirmado.
    Route::post('/logout', [AuthController::class, 'logout'])->withoutMiddleware('verified.if');
    Route::get('/me', [AuthController::class, 'me'])->withoutMiddleware('verified.if');
    Route::patch('/settings', [AuthController::class, 'updateSettings'])->withoutMiddleware('verified.if');
    Route::post('/email/resend', [VerifyEmailController::class, 'resend'])->withoutMiddleware('verified.if')->middleware('throttle:3,1');

    Route::post('/activity', [\App\Http\Controllers\ActivityController::class, 'beat'])->middleware('throttle:20,1');

    Route::prefix('admin')->middleware('admin')->withoutMiddleware('verified.if')->group(function () {
        $c = \App\Http\Controllers\AdminController::class;
        Route::get('/dashboard', [$c, 'dashboard']);
        Route::get('/users', [$c, 'users']);
        Route::get('/users/{user}', [$c, 'user'])->whereNumber('user');
        Route::post('/users/{user}/{action}', [$c, 'userAction'])->whereNumber('user')->whereIn('action', ['deactivate', 'activate', 'block', 'unblock']);
        Route::get('/games', [$c, 'games']);
        Route::get('/games/{key}/cards', [$c, 'gameCards']);
        Route::get('/exercises', [$c, 'exercises']);
        Route::post('/exercises', [$c, 'storeExercise']);
        Route::put('/exercises/{item}', [$c, 'updateExercise'])->whereNumber('item');
        Route::delete('/exercises/{item}', [$c, 'destroyExercise'])->whereNumber('item');
        Route::put('/cards/{card}', [$c, 'updateCard'])->whereNumber('card');
    });

    Route::post('/couple/invite', [CoupleController::class, 'invite']);
    Route::post('/couple/accept', [CoupleController::class, 'accept']);
    Route::delete('/couple', [CoupleController::class, 'leave']);

    Route::get('/catalog/next', [CatalogController::class, 'next']);
    Route::put('/catalog/{item}/answer', [CatalogController::class, 'answer']);
    Route::get('/intersections', [IntersectionController::class, 'index']);

    Route::post('/games/quem/rounds', [QuemController::class, 'start']);
    Route::get('/games/quem/current', [QuemController::class, 'current']);
    Route::put('/games/quem/rounds/{round}/answer', [QuemController::class, 'answer']);

    Route::post('/games/vod/draw', [VodController::class, 'draw']);
    Route::get('/games/vod/current', [VodController::class, 'current']);
    Route::put('/games/vod/rounds/{round}/answer', [VodController::class, 'answer']);

    Route::post('/games/choice/{game}/draw', [ChoiceController::class, 'draw'])->whereIn('game', ['ice', 'yk']);
    Route::get('/games/choice/{game}/current', [ChoiceController::class, 'current'])->whereIn('game', ['ice', 'yk']);
    Route::put('/games/choice/{game}/rounds/{round}/answer', [ChoiceController::class, 'answer'])->whereIn('game', ['ice', 'yk']);
    Route::get('/games/dados', [DiceController::class, 'index']);

    Route::post('/games/board', [BoardController::class, 'start']);
    Route::get('/games/board/current', [BoardController::class, 'current']);
    Route::post('/games/board/{board}/roll', [BoardController::class, 'roll']);
    Route::post('/games/board/{board}/resolve', [BoardController::class, 'resolve']);

    Route::get('/cards', [CoupleExtrasController::class, 'cards']);
    Route::post('/cards', [CoupleExtrasController::class, 'storeCard']);
    Route::delete('/cards/{card}', [CoupleExtrasController::class, 'destroyCard']);
    Route::get('/notes', [CoupleExtrasController::class, 'notes']);
    Route::post('/notes', [CoupleExtrasController::class, 'storeNote'])->middleware('throttle:30,1');
    Route::put('/couple/reunion', [CoupleExtrasController::class, 'reunion']);
    Route::get('/ideas', [CoupleExtrasController::class, 'ideas']);
    Route::put('/ideas/vote', [CoupleExtrasController::class, 'vote']);

    Route::prefix('public')->group(function () {
        Route::get('/profile', [PublicMatchController::class, 'profile']);
        Route::put('/profile', [PublicMatchController::class, 'saveProfile']);
        Route::get('/couple', [PublicMatchController::class, 'couple']);
        Route::post('/couple/consent', [PublicMatchController::class, 'coupleConsent']);
        Route::post('/couple/reviews/{id}', [PublicMatchController::class, 'coupleReview'])->whereNumber('id');
        Route::get('/suggestions',[PublicMatchController::class, 'suggestions']);
        Route::post('/pass', [PublicMatchController::class, 'pass'])->middleware('throttle:120,1');
        Route::get('/candidates/{publicId}/photo', [PublicMatchController::class, 'candidatePhoto'])->where('publicId', '[0-9A-Za-z]{26}');
        Route::post('/request', [PublicMatchController::class, 'request'])->middleware('throttle:30,1');
        Route::get('/requests', [PublicMatchController::class, 'requests']);
        Route::post('/requests/{id}/respond', [PublicMatchController::class, 'respond'])->whereNumber('id');
        Route::get('/matches', [PublicMatchController::class, 'matches']);
        Route::get('/matches/{id}/messages', [PublicMatchController::class, 'messages'])->whereNumber('id');
        Route::post('/matches/{id}/messages', [PublicMatchController::class, 'send'])->whereNumber('id')->middleware('throttle:60,1');
        Route::post('/matches/{id}/reveal', [PublicMatchController::class, 'revealAsk'])->whereNumber('id')->middleware('throttle:10,1');
        Route::post('/matches/{id}/reveal/respond', [PublicMatchController::class, 'revealRespond'])->whereNumber('id');
        Route::post('/matches/{id}/unmatch', [PublicMatchController::class, 'unmatch'])->whereNumber('id');
        Route::post('/block', [PublicMatchController::class, 'blockUser']);
        Route::post('/report', [PublicMatchController::class, 'report'])->middleware('throttle:10,1');
        Route::post('/photos', [PublicMatchController::class, 'uploadPhoto'])->middleware('throttle:20,1');
        Route::delete('/photos/{id}', [PublicMatchController::class, 'deletePhoto'])->whereNumber('id');
        Route::get('/photos/{id}', [PublicMatchController::class, 'photo'])->whereNumber('id');
    });

    Route::get('/limits', [LimitController::class, 'index']);
    Route::put('/limits', [LimitController::class, 'update']);
    Route::post('/catalog/suggest', [CatalogController::class, 'suggest'])->middleware('throttle:10,1');
    Route::get('/catalog/answered', [CatalogController::class, 'answered']);
    Route::delete('/catalog/{item}/answer', [CatalogController::class, 'undo']);

    Route::post('/games/stop', [GameController::class, 'stop']);

    Route::put('/account/password', [AccountController::class, 'password'])->withoutMiddleware('verified.if');
    Route::put('/account/email', [AccountController::class, 'email'])->withoutMiddleware('verified.if');
    Route::put('/account/pin', [AccountController::class, 'setPin'])->withoutMiddleware('verified.if');
    Route::delete('/account/pin', [AccountController::class, 'removePin'])->withoutMiddleware('verified.if');
    Route::post('/account/pin/verify', [AccountController::class, 'verifyPin'])->withoutMiddleware('verified.if')->middleware('throttle:5,1');
    Route::delete('/account', [AccountController::class, 'destroy'])->withoutMiddleware('verified.if');

    Route::get('/games/noites', [NightController::class, 'index']);
    Route::put('/games/noites/{number}', [NightController::class, 'mark'])->whereNumber('number');
});
