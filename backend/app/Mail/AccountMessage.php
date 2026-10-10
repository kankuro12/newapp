<?php

namespace App\Mail;

use App\Service\NotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AccountMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $event, public string $recipientName, public array $context = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: app(NotificationService::class)->message($this->event, $this->recipientName, $this->context)['subject']);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.account-message', text: 'emails.account-message-text', with: app(NotificationService::class)->message($this->event, $this->recipientName, $this->context));
    }
}
