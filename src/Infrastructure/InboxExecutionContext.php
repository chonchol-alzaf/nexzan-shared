<?php

namespace Nexzan\Shared\Infrastructure;

use Nexzan\Shared\Models\InboxEvent;

class InboxExecutionContext
{
    public ?string $eventId = null;

    public ?InboxEvent $inbox = null;
}
