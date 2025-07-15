<?php

namespace Nexzan\Shared\Enums;

use BenSampo\Enum\Enum;

final class TeamStatusEnum extends Enum
{
    const ACTIVE       = 1;
    const TRAIL        = 2;
    const INACTIVE     = 3;
    const SUSPEND      = 4;
    const TRIAL_ENDED  = 5;
}