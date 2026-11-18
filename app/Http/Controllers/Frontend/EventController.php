<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Events\EventInterface;
use App\Enums\EventScope;
use App\Http\Controllers\Controller;
use App\Http\Resources\EventResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
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
}
