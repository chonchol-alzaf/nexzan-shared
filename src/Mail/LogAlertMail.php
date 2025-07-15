<?php

namespace Nexzan\Shared\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class LogAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    
    public function __construct(public string $messageText,public string $level,public string $timestamp)
    {
        //
    }

   
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "📨 Log [$this->level] Alert - $this->timestamp",
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
             view: 'nexzan-shared::emails.log-alert',
        );
    }

    
    public function attachments(): array
    {
        return [];
    }
}
