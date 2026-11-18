<?php

use App\Enums\TokenAbility;
use App\Http\Controllers\Frontend\AuthController;
use App\Http\Controllers\Frontend\EventController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\InvitationController;
use App\Http\Controllers\Frontend\NotificationController;
use App\Http\Controllers\Frontend\WalletController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function () {
    Route::prefix('auth')->controller(AuthController::class)->group(function () {
        Route::get('options', 'options');

        Route::middleware('throttle:otp')->group(function () {
            Route::post('register', 'register');
            Route::post('resend-otp', 'resendOtp');
            Route::post('login/otp', 'sendLoginOtp');
        });

        Route::middleware('throttle:auth')->group(function () {
            Route::post('verify-otp', 'verifyOtp');
            Route::post('login', 'login');
            Route::post('login/otp/verify', 'verifyLoginOtp');
        });

        Route::post('refresh', 'refresh')
            ->middleware(['auth:sanctum', 'abilities:'.TokenAbility::IssueAccessToken->value]);
    });

    Route::middleware(['auth:sanctum', 'abilities:'.TokenAbility::AccessApi->value])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);

        Route::get('home', HomeController::class);

        Route::get('events', [EventController::class, 'index']);

        Route::controller(InvitationController::class)->prefix('invitations')->group(function () {
            Route::get('/', 'index');
            Route::post('{invitation}/accept', 'accept')->whereNumber('invitation');
            Route::post('{invitation}/decline', 'decline')->whereNumber('invitation');
        });

        Route::get('wallet', [WalletController::class, 'show']);

        Route::controller(NotificationController::class)->prefix('notifications')->group(function () {
            Route::get('/', 'index');
            Route::post('{notification}/read', 'markRead')->whereUuid('notification');
        });
    });
});
