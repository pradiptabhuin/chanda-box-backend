<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Wallet\WalletInterface;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WalletController extends Controller
{
    public function __construct(private WalletInterface $walletInterface) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json($this->walletInterface->summary($request->user()));
    }
}
