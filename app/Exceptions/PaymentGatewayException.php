<?php

namespace App\Exceptions;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The payment provider refused or couldn't be reached. The details are logged;
 * the app only gets a generic, retryable message.
 */
class PaymentGatewayException extends RuntimeException
{
    public function report(): void
    {
        Log::error($this->getMessage(), ['exception' => $this->getPrevious()]);
    }

    public function render(): JsonResponse
    {
        return response()->json(['message' => 'The payment service is unavailable right now. Please try again.'], 502);
    }
}
