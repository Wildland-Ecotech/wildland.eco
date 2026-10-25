<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A short message sent by `php artisan mail:test`, to check that a server
 * can send email before donors depend on it.
 */
class TestEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public string $environment) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Test email from {$this->environment}");
    }

    public function content(): Content
    {
        return new Content(text: 'mail.test-email');
    }
}
