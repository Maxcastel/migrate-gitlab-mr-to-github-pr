<?php

declare(strict_types=1);

namespace App\Entity;

enum IssueState
{
    case Open;
    case Closed;
}
