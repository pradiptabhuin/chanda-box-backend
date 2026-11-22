<?php

namespace App\Providers;

use App\Core\Auth\AuthInterface;
use App\Core\Auth\AuthRepository;
use App\Core\Events\EventInterface;
use App\Core\Events\EventRepository;
use App\Core\Invitations\InvitationInterface;
use App\Core\Invitations\InvitationRepository;
use App\Core\Otp\FirebaseOtpProvider;
use App\Core\Otp\NullOtpProvider;
use App\Core\Otp\OtpInterface;
use App\Core\Otp\SmsOtpProvider;
use App\Core\Payments\PaymentGatewayInterface;
use App\Core\Payments\PaymentInterface;
use App\Core\Payments\PaymentRepository;
use App\Core\Payments\RazorpayGateway;
use App\Core\Push\FcmPushProvider;
use App\Core\Push\LogPushProvider;
use App\Core\Push\PushInterface;
use App\Core\Sms\LogSmsProvider;
use App\Core\Sms\SmsInterface;
use App\Core\Wallet\WalletInterface;
use App\Core\Wallet\WalletRepository;
use App\Http\Requests\Auth\PhoneRequest;
use App\Models\Contribution;
use App\Models\Withdrawal;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(EventInterface::class, EventRepository::class);
        $this->app->bind(AuthInterface::class, AuthRepository::class);
        $this->app->bind(InvitationInterface::class, InvitationRepository::class);
        $this->app->bind(WalletInterface::class, WalletRepository::class);
        $this->app->bind(PaymentInterface::class, PaymentRepository::class);
        $this->app->singleton(PaymentGatewayInterface::class, fn () => RazorpayGateway::fromConfig());

        $this->app->bind(SmsInterface::class, fn () => match (config('services.sms.driver')) {
            'log' => new LogSmsProvider,
            default => throw new InvalidArgumentException('Unsupported SMS_DRIVER ['.config('services.sms.driver').'].'),
        });

        $this->app->bind(OtpInterface::class, fn ($app) => match (config('services.otp.driver')) {
            'none' => new NullOtpProvider,
            'sms' => new SmsOtpProvider($app->make(SmsInterface::class)),
            'firebase' => new FirebaseOtpProvider(config('services.firebase.project_id')),
            default => throw new InvalidArgumentException('Unsupported OTP_DRIVER ['.config('services.otp.driver').'].'),
        });

        $this->app->bind(PushInterface::class, fn () => match (config('services.push.driver')) {
            'log' => new LogPushProvider,
            'fcm' => FcmPushProvider::fromCredentialsFile(config('services.firebase.credentials')),
            default => throw new InvalidArgumentException('Unsupported PUSH_DRIVER ['.config('services.push.driver').'].'),
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Relation::morphMap([
            'contribution' => Contribution::class,
            'withdrawal' => Withdrawal::class,
        ]);

        RateLimiter::for('otp', function (Request $request) {
            $phone = PhoneRequest::normalizePhone($request->input('phone'));

            return [
                Limit::perMinute(3)->by("otp:{$phone}"),
                Limit::perHour(10)->by("otp:{$phone}"),
                Limit::perHour(30)->by("otp-ip:{$request->ip()}"),
            ];
        });

        RateLimiter::for('auth', function (Request $request) {
            $phone = PhoneRequest::normalizePhone($request->input('phone'));

            return [
                Limit::perMinute(5)->by("auth:{$phone}"),
                Limit::perMinute(20)->by("auth-ip:{$request->ip()}"),
            ];
        });
    }
}
