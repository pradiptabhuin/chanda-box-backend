<?php

namespace App\Http\Controllers\Frontend;

use App\Core\Events\EventInterface;
use App\Http\Controllers\Controller;

class EventController extends Controller
{
    private $eventInterface;

    public function __construct(EventInterface $eventInterface)
    {
        $this->eventInterface = $eventInterface;
    }
}
