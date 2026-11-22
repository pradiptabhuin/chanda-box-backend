<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Events\EventInterface;
use App\Enums\EventScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Event\SaveEventRequest;
use App\Http\Requests\Event\UpdateEventStatusRequest;
use App\Http\Resources\ContributionResource;
use App\Http\Resources\EventMemberResource;
use App\Http\Resources\EventResource;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function __construct(private EventInterface $eventInterface) {}

    /**
     * GET /events?scope=owned|joined
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'scope' => ['required', Rule::enum(EventScope::class)],
        ]);

        return EventResource::collection(
            $this->eventInterface->forUser($request->user(), EventScope::from($validated['scope']))
        );
    }

    public function store(SaveEventRequest $request): JsonResponse
    {
        $event = $this->eventInterface->create($request->user(), $request->eventData());

        return (new EventResource($event))->response()->setStatusCode(201);
    }

    public function show(Request $request, int $event): EventResource
    {
        $model = $this->eventInterface->findForUser($request->user(), $event);
        Gate::authorize('view', $model);

        return new EventResource($model);
    }

    public function update(SaveEventRequest $request, Event $event): EventResource
    {
        return new EventResource($this->eventInterface->update($event, $request->eventData()));
    }

    /**
     * PUT /events/{event}/status
     */
    public function updateStatus(UpdateEventStatusRequest $request, Event $event): EventResource
    {
        return new EventResource($this->eventInterface->changeStatus($event, $request->status()));
    }

    /**
     * DELETE /events/{event}/members/{member}: remove a member or cancel an invitation.
     */
    public function removeMember(Event $event, int $member): JsonResponse
    {
        Gate::authorize('removeMember', $event);
        $this->eventInterface->removeMember($event, $member);

        return response()->json(['message' => 'Removed.']);
    }

    public function members(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewActivity', $event);

        return EventMemberResource::collection($this->eventInterface->members($event));
    }

    public function contributions(Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewActivity', $event);

        return ContributionResource::collection($this->eventInterface->contributions($event));
    }
}
