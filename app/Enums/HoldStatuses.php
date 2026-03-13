<?php

namespace App\Enums;

enum HoldStatuses: string
{
    case STATUS_HELD = 'held';
    case STATUS_CONFIRMED = 'confirmed';
    case STATUS_CANCELLED = 'cancelled';
}
