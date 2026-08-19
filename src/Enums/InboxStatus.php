<?php

namespace Nexzan\Shared\Enums;

enum InboxStatus: string
{
    case Pending = 'pending';
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Dead = 'dead';
}
