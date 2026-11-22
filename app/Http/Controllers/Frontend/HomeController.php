<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Events\EventInterface;
use App\Core\Invitations\InvitationInterface;
use App\Core\Transactions\TransactionInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use App\Http\Resources\InvitationResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Everything the home screen needs in one request. Each part has the same shape as its
 * own endpoint (/transactions summary, /events?scope=owned|joined, /invitations), so the app reuses its models.
 */
class HomeController extends Controller
{
    public function __construct(
        private EventInterface $eventInterface,
        private InvitationInterface $invitationInterface,
        private TransactionInterface $transactionInterface,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();

        // Owned and joined events come from a single query and are split here.
        [$owned, $joined] = $this->eventInterface->forUser($user)
            ->partition(fn (Event $event) => $event->owner_id === $user->id);

        return response()->json([
            'transactions' => $this->transactionInterface->summary($user),
            'events' => [
                'owned' => EventResource::collection($owned->values()),
                'joined' => EventResource::collection($joined->values()),
            ],
            'invitations' => InvitationResource::collection($this->invitationInterface->pendingFor($user)),
        ]);
    }
}
