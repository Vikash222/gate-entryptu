<?php

namespace App\Events;

use App\Models\Gate;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GateActivityAlert
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $alertType,
        public string $message,
        public ?Gate $gate = null,
        public array $metadata = []
    ) {}
}
