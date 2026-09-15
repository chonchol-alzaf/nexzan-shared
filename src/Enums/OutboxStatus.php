<?php

namespace Nexzan\Shared\Enums;

enum OutboxStatus: string
{
    case Pending = 'pending';
    case Publishing = 'publishing';
    case Published = 'published';
    case Failed = 'failed';
    case Dead = 'dead';
}
