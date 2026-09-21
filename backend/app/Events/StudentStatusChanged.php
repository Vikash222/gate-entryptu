<?php

namespace App\Events;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $statusAction,
        public Student $student,
        public ?User $admin = null
    ) {}
}
