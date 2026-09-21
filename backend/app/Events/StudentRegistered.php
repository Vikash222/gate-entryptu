<?php

namespace App\Events;

use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class StudentRegistered
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Student $student,
        public User $user
    ) {}
}
