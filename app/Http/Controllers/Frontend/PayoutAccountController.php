<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Payouts\PayoutAccountInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Payout\SavePayoutAccountRequest;
use App\Http\Resources\PayoutAccountResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The signed-in user's payout account (where Razorpay Route pays their events' online contributions).
 */
class PayoutAccountController extends Controller
{
    public function __construct(private PayoutAccountInterface $payoutAccountInterface) {}

    public function show(Request $request): JsonResponse
    {
        $account = $this->payoutAccountInterface->find($request->user());

        return response()->json(['data' => $account ? new PayoutAccountResource($account) : null]);
    }

    /**
     * PUT /payout-account: create or replace (always 200).
     */
    public function update(SavePayoutAccountRequest $request): JsonResponse
    {
        return (new PayoutAccountResource($this->payoutAccountInterface->save($request->user(), $request->validated())))
            ->response()
            ->setStatusCode(200);
    }

    /**
     * POST /payout-account/retry: link with Razorpay again.
     */
    public function retry(Request $request): PayoutAccountResource
    {
        $account = $request->user()->payoutAccount()->first() ?? abort(404, 'Add a payout account first.');

        return new PayoutAccountResource($this->payoutAccountInterface->link($account));
    }

    public function destroy(Request $request): JsonResponse
    {
        $this->payoutAccountInterface->remove($request->user());

        return response()->json(['message' => 'Payout account removed.']);
    }
}
