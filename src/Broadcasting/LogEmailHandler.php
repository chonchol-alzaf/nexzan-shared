<?php

namespace Nexzan\Shared\Broadcasting;

use App\Mail\LogAlertMail;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\AbstractProcessingHandler;

class LogEmailHandler extends AbstractProcessingHandler
{
    protected function write($record): void
    {
        $message = $record['message'];
        $level = $record['level_name'] ?? 'UNKNOWN';
        $timestamp = $record['datetime']->format('Y-m-d H:i:s');

        Mail::to(config('app.log_notification_email','suppor@nexzan.com'))
            ->send(new LogAlertMail($message, $level, $timestamp));
    }
}
