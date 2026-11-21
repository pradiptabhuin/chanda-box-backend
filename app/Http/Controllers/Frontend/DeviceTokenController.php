<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Device\DeviceTokenRequest;
use App\Models\DeviceToken;
use Illuminate\Http\JsonResponse;

/**
 * FCM tokens of the signed-in user's devices. The app registers its token after login
 * (and whenever FCM rotates it) and removes it on logout.
 */
class DeviceTokenController extends Controller
{
    /**
     * A token belongs to one device, so it moves to whoever signed in on that device last.
     */
    public function store(DeviceTokenRequest $request): JsonResponse
    {
        DeviceToken::updateOrCreate(
            ['token' => $request->validated('token')],
            [
                'user_id' => $request->user()->id,
                'platform' => $request->validated('platform'),
                'last_seen_at' => now(),
            ],
        );

        return response()->json(['message' => 'Device registered.']);
    }

    public function destroy(DeviceTokenRequest $request): JsonResponse
    {
        $request->user()->deviceTokens()->where('token', $request->validated('token'))->delete();

        return response()->json(['message' => 'Device removed.']);
    }
}
