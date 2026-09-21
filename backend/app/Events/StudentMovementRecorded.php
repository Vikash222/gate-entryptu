<?php

namespace App\Events;

use App\Models\Movement;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentMovementRecorded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Movement $movement
    ) {}
}
