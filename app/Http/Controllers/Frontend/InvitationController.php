<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Invitations\InvitationInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Event\InviteMembersRequest;
use App\Http\Resources\InvitationResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class InvitationController extends Controller
{
    public function __construct(private InvitationInterface $invitationInterface) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        return InvitationResource::collection($this->invitationInterface->pendingFor($request->user()));
    }

    /**
     * POST /events/{event}/invitations: the owner invites phone numbers.
     */
    public function store(InviteMembersRequest $request, Event $event): JsonResponse
    {
        return response()->json(
            $this->invitationInterface->invite($event, $request->user(), $request->phones())
        );
    }

    public function accept(Request $request, int $invitation): JsonResponse
    {
        $this->invitationInterface->accept($request->user(), $invitation);

        return response()->json(['message' => 'Invitation accepted.']);
    }

    public function decline(Request $request, int $invitation): JsonResponse
    {
        $this->invitationInterface->decline($request->user(), $invitation);

        return response()->json(['message' => 'Invitation declined.']);
    }
}
