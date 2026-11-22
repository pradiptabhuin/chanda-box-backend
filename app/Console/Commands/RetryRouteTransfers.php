<?php

namespace App\Console\Commands;

use App\Core\Payments\RouteTransfer;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('payments:transfer')]
#[Description('Transfer captured contributions that have not yet reached the organiser\'s Route account')]
class RetryRouteTransfers extends Command
{
    public function handle(RouteTransfer $transfers): int
    {
        $this->info("Tried {$transfers->retryOutstanding()} transfer(s).");

        return self::SUCCESS;
    }
}
