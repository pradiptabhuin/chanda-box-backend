<?php

namespace App\Http\Controllers\Frontend;

use App\Core\CashRequests\CashRequestInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payment\RejectCashRequestRequest;
use App\Http\Requests\Payment\StoreCashRequestRequest;
use App\Http\Resources\CashRequestResource;
use App\Models\CashRequest;
use App\Models\Event;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CashRequestController extends Controller
{
    public function __construct(private CashRequestInterface $cashRequestInterface) {}

    /**
     * GET /events/{event}/cash-requests: pending ones (all for the owner, own for a member).
     */
    public function index(Request $request, Event $event): AnonymousResourceCollection
    {
        Gate::authorize('viewActivity', $event);

        return CashRequestResource::collection($this->cashRequestInterface->pendingFor($request->user(), $event));
    }

    /**
     * POST /events/{event}/cash-requests: cash or direct UPI; `approved` right away when the owner records it, else `pending`.
     */
    public function store(StoreCashRequestRequest $request, Event $event): JsonResponse
    {
        $cashRequest = $this->cashRequestInterface->create(
            $request->user(),
            $event,
            $request->amount(),
            $request->member(),
            $request->note(),
            $request->paymentMethod(),
            $request->utr(),
        );

        return (new CashRequestResource($cashRequest))->response()->setStatusCode(201);
    }

    public function approve(Request $request, CashRequest $cashRequest): CashRequestResource
    {
        Gate::authorize('review', $cashRequest);

        return new CashRequestResource($this->cashRequestInterface->approve($request->user(), $cashRequest));
    }

    public function reject(RejectCashRequestRequest $request, CashRequest $cashRequest): CashRequestResource
    {
        return new CashRequestResource(
            $this->cashRequestInterface->reject($request->user(), $cashRequest, $request->validated('reason')),
        );
    }

    public function cancel(CashRequest $cashRequest): CashRequestResource
    {
        Gate::authorize('cancel', $cashRequest);

        return new CashRequestResource($this->cashRequestInterface->cancel($cashRequest));
    }
}
