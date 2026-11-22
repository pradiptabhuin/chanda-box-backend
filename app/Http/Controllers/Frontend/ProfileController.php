<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Auth\AuthInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Profile\ChangePasswordRequest;
use App\Http\Requests\Profile\UpdateProfilePhotoRequest;
use App\Http\Requests\Profile\UpdateProfileRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * The signed-in user's own profile and password.
 */
class ProfileController extends Controller
{
    public function __construct(private AuthInterface $authInterface) {}

    /**
     * PUT /me
     */
    public function update(UpdateProfileRequest $request): UserResource
    {
        $user = $request->user()->fill($request->validated());

        // A new address hasn't been verified yet.
        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $user->save();

        return new UserResource($user);
    }

    /**
     * POST /me/photo: stores the picture as storage/app/public/avatars/{user id}/{random}.{ext}
     * and deletes the previous one. A new file name each time means no stale caches.
     */
    public function updatePhoto(UpdateProfilePhotoRequest $request): UserResource
    {
        $user = $request->user();
        $previous = $user->profile_pic;

        $user->update(['profile_pic' => $request->photo()->store("avatars/{$user->id}", User::PHOTO_DISK)]);
        $this->deletePhotoFile($previous);

        return new UserResource($user);
    }

    /**
     * DELETE /me/photo
     */
    public function deletePhoto(Request $request): UserResource
    {
        $user = $request->user();
        $previous = $user->profile_pic;

        $user->update(['profile_pic' => null]);
        $this->deletePhotoFile($previous);

        return new UserResource($user);
    }

    /**
     * PUT /me/password: keeps this device signed in and signs out every other one.
     */
    public function password(ChangePasswordRequest $request): JsonResponse
    {
        $user = $request->user();
        $user->update(['password' => $request->validated('password')]);
        $this->authInterface->revokeOtherSessions($user, $user->currentAccessToken());

        return response()->json(['message' => 'Password changed.']);
    }

    private function deletePhotoFile(?string $path): void
    {
        if (filled($path)) {
            Storage::disk(User::PHOTO_DISK)->delete($path);
        }
    }
}
