<?php

namespace App\Enums;

enum VisitStatus: string
{
    case Waiting = 'waiting';
    case Called = 'called';
    case Skipped = 'skipped';
    case Completed = 'completed';
}
