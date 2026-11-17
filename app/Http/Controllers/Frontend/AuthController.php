<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Auth\AuthInterface;
use App\Core\Otp\OtpInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\PhoneOnlyRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Requests\Auth\VerifyOtpRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    public function __construct(
        private AuthInterface $authInterface,
        private OtpInterface $otpInterface,
    ) {}

    /**
     * Tell the app which login and registration flows are available.
     */
    public function options(): JsonResponse
    {
        return response()->json([
            'otp_enabled' => $this->otpInterface->isEnabled(),
            'otp_driver' => $this->otpInterface->driver(),
        ]);
    }

    /**
     * With OTP disabled the user is created and logged in straight away. Otherwise the
     * registration is kept pending, an OTP is sent and the user is created in verifyOtp().
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        if (! $this->otpInterface->isEnabled()) {
            $user = $this->authInterface->createUser($request->validated());

            return $this->sessionResponse($user, $this->authInterface->issueTokens($user), 201);
        }

        $this->authInterface->storePendingRegistration($request->validated());
        $this->otpInterface->send($request->phone);

        return $this->otpSentResponse();
    }

    /**
     * Verify the registration OTP, create the user and start a session.
     */
    public function verifyOtp(VerifyOtpRequest $request): JsonResponse
    {
        $this->ensureOtpEnabled();

        if (! $this->authInterface->hasPendingRegistration($request->phone)) {
            throw ValidationException::withMessages([
                'phone' => 'Registration expired or not found. Please register again.',
            ]);
        }

        $this->ensureValidOtp($request->phone, $request->otp);

        $user = $this->authInterface->completeRegistration($request->phone);

        if ($user === null) {
            throw ValidationException::withMessages([
                'phone' => 'Registration expired or not found. Please register again.',
            ]);
        }

        return $this->sessionResponse($user, $this->authInterface->issueTokens($user), 201);
    }

    /**
     * Resend the OTP for a pending registration or an OTP login.
     */
    public function resendOtp(PhoneOnlyRequest $request): JsonResponse
    {
        $this->ensureOtpEnabled();

        if (! $this->authInterface->hasPendingRegistration($request->phone)
            && $this->authInterface->findByPhone($request->phone) === null) {
            throw ValidationException::withMessages([
                'phone' => 'No pending registration or account found for this phone number.',
            ]);
        }

        $this->otpInterface->send($request->phone);

        return $this->otpSentResponse();
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $user = $this->authInterface->findByPhone($request->phone);

        if ($user === null || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'phone' => 'The phone number or password is incorrect.',
            ]);
        }

        return $this->sessionResponse($user, $this->authInterface->issueTokens($user));
    }

    /**
     * Start an OTP login for a registered phone number.
     */
    public function sendLoginOtp(PhoneOnlyRequest $request): JsonResponse
    {
        $this->ensureOtpEnabled();

        if ($this->authInterface->findByPhone($request->phone) === null) {
            throw ValidationException::withMessages([
                'phone' => 'No account found for this phone number.',
            ]);
        }

        $this->otpInterface->send($request->phone);

        return $this->otpSentResponse();
    }

    public function verifyLoginOtp(VerifyOtpRequest $request): JsonResponse
    {
        $this->ensureOtpEnabled();

        $user = $this->authInterface->findByPhone($request->phone);

        if ($user === null) {
            throw ValidationException::withMessages([
                'phone' => 'No account found for this phone number.',
            ]);
        }

        $this->ensureValidOtp($request->phone, $request->otp);

        return $this->sessionResponse($user, $this->authInterface->issueTokens($user));
    }

    /**
     * Exchange a refresh token (sent as the bearer token) for a new access + refresh pair.
     */
    public function refresh(Request $request): JsonResponse
    {
        $tokens = $this->authInterface->refreshTokens($request->user(), $request->user()->currentAccessToken());

        if ($tokens === null) {
            return response()->json(['message' => 'Refresh token has already been used.'], 401);
        }

        return $this->sessionResponse($request->user(), $tokens);
    }

    public function logout(Request $request): JsonResponse
    {
        $this->authInterface->revokeSession($request->user(), $request->user()->currentAccessToken());

        return response()->json(['message' => 'Logged out.']);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user());
    }

    private function ensureOtpEnabled(): void
    {
        if (! $this->otpInterface->isEnabled()) {
            throw ValidationException::withMessages([
                'otp' => 'OTP verification is not available. Please use your phone number and password.',
            ]);
        }
    }

    private function ensureValidOtp(string $phone, string $otp): void
    {
        if (! $this->otpInterface->verify($phone, $otp)) {
            throw ValidationException::withMessages([
                'otp' => 'The OTP is invalid or has expired.',
            ]);
        }
    }

    private function otpSentResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'OTP sent.',
            'otp_driver' => $this->otpInterface->driver(),
        ]);
    }

    /**
     * @param  array{token: string, token_type: string, expires_at: string, refresh_token: string, refresh_token_expires_at: string}  $tokens
     */
    private function sessionResponse(User $user, array $tokens, int $status = 200): JsonResponse
    {
        return response()->json([
            ...$tokens,
            'user' => new UserResource($user),
        ], $status);
    }
}
