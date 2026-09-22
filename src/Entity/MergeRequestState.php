<?php

declare(strict_types=1);

namespace App\Entity;

enum MergeRequestState
{
    case Opened;
    case Merged;
    case Closed;
}
