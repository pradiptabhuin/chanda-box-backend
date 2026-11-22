<?php

use App\Enums\TokenAbility;
use App\Http\Controllers\Frontend\AuthController;
use App\Http\Controllers\Frontend\CashRequestController;
use App\Http\Controllers\Frontend\DeviceTokenController;
use App\Http\Controllers\Frontend\EventController;
use App\Http\Controllers\Frontend\HomeController;
use App\Http\Controllers\Frontend\InvitationController;
use App\Http\Controllers\Frontend\NotificationController;
use App\Http\Controllers\Frontend\PaymentController;
use App\Http\Controllers\Frontend\PayoutAccountController;
use App\Http\Controllers\Frontend\ProfileController;
use App\Http\Controllers\Frontend\RazorpayWebhookController;
use App\Http\Controllers\Frontend\TransactionController;
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

    Route::post('webhooks/razorpay', RazorpayWebhookController::class);

    Route::middleware(['auth:sanctum', 'abilities:'.TokenAbility::AccessApi->value])->group(function () {
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me', [AuthController::class, 'me']);
        Route::put('me', [ProfileController::class, 'update']);
        Route::post('me/photo', [ProfileController::class, 'updatePhoto']);
        Route::delete('me/photo', [ProfileController::class, 'deletePhoto']);
        Route::put('me/password', [ProfileController::class, 'password'])->middleware('throttle:password');

        Route::controller(DeviceTokenController::class)->prefix('me/device-token')->group(function () {
            Route::post('/', 'store');
            Route::delete('/', 'destroy');
        });

        Route::get('home', HomeController::class);

        Route::controller(EventController::class)->prefix('events')->group(function () {
            Route::get('/', 'index');
            Route::post('/', 'store');
            Route::get('{event}', 'show')->whereNumber('event');
            Route::put('{event}', 'update')->whereNumber('event');
            Route::put('{event}/status', 'updateStatus')->whereNumber('event');
            Route::get('{event}/members', 'members')->whereNumber('event');
            Route::delete('{event}/members/{member}', 'removeMember')->whereNumber(['event', 'member']);
            Route::get('{event}/contributions', 'contributions')->whereNumber('event');
        });

        Route::post('events/{event}/invitations', [InvitationController::class, 'store'])->whereNumber('event');

        Route::controller(InvitationController::class)->prefix('invitations')->group(function () {
            Route::get('/', 'index');
            Route::post('{invitation}/accept', 'accept')->whereNumber('invitation');
            Route::post('{invitation}/decline', 'decline')->whereNumber('invitation');
        });

        Route::get('events/{event}/payments/quote', [PaymentController::class, 'quote'])->whereNumber('event');
        Route::post('events/{event}/payments/order', [PaymentController::class, 'store'])->whereNumber('event');

        Route::controller(PaymentController::class)->prefix('payments')->group(function () {
            Route::post('verify', 'verify');
            Route::get('{orderId}/status', 'status');
        });

        Route::controller(CashRequestController::class)->group(function () {
            Route::get('events/{event}/cash-requests', 'index')->whereNumber('event');
            Route::post('events/{event}/cash-requests', 'store')->whereNumber('event');
            Route::post('cash-requests/{cashRequest}/approve', 'approve')->whereNumber('cashRequest');
            Route::post('cash-requests/{cashRequest}/reject', 'reject')->whereNumber('cashRequest');
            Route::post('cash-requests/{cashRequest}/cancel', 'cancel')->whereNumber('cashRequest');
        });

        Route::get('transactions', [TransactionController::class, 'index']);

        Route::controller(PayoutAccountController::class)->prefix('payout-account')->group(function () {
            Route::get('/', 'show');
            Route::put('/', 'update');
            Route::delete('/', 'destroy');
            Route::post('retry', 'retry');
        });

        Route::controller(NotificationController::class)->prefix('notifications')->group(function () {
            Route::get('/', 'index');
            Route::post('read-all', 'markAllRead');
            Route::post('{notification}/read', 'markRead')->whereUuid('notification');
        });
    });
});
