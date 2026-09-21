<?php

namespace App\Events;

use App\Models\Gate;
use App\Models\SecurityDutySession;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SecurityDutyChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $event,
        public SecurityDutySession $session,
        public User $guard,
        public ?Gate $gate = null
    ) {}
}
