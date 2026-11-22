<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Transactions\TransactionInterface;
use App\Enums\TransactionFilter;
use App\Http\Controllers\Controller;
use App\Http\Resources\TransactionResource;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\Rule;

class TransactionController extends Controller
{
    public function __construct(private TransactionInterface $transactionInterface) {}

    /**
     * GET /transactions?filter=all|received|sent&page=N — history plus the to-date summary.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'filter' => ['nullable', Rule::enum(TransactionFilter::class)],
        ]);
        $user = $request->user();

        return TransactionResource::collection(
            $this->transactionInterface->history($user, TransactionFilter::tryFrom($validated['filter'] ?? '') ?? TransactionFilter::All)
                ->withQueryString()
        )->additional(['summary' => $this->transactionInterface->summary($user)]);
    }
}
