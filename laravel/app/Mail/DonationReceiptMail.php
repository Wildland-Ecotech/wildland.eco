<?php

namespace App\Mail;

use App\Models\DonationReceipt;
use App\Models\Subscription;
use App\Services\DonationReceipts;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A donation's tax receipt: the written acknowledgment donors keep for
 * their taxes, with the organization's legal name, address, and EIN, the
 * receipt number, date, and amount, and the statement that no goods or
 * services were provided in exchange. Sent by DonationReceipts.
 */
class DonationReceiptMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public DonationReceipt $receipt) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Your donation receipt from Wildland Ecotech ({$this->receipt->receipt_number})",
        );
    }

    public function content(): Content
    {
        $donation = $this->receipt->donation;
        $subscription = $donation->subscription;
        $cents = $this->receipt->amount_cents;

        return new Content(
            markdown: 'mail.donation-receipt',
            with: [
                'name' => ($donation->user ?? $donation->user()->withTrashed()->first())?->name,
                'amount' => '$' . number_format($cents / 100, 2),
                'date' => $donation->paid_at->copy()->timezone(DonationReceipts::TIMEZONE)->format('F j, Y'),
                'kind' => match ($subscription?->interval) {
                    Subscription::INTERVAL_MONTH => 'Monthly recurring donation',
                    Subscription::INTERVAL_YEAR => 'Annual recurring donation',
                    default => 'One-time donation',
                },
                'recurring' => $subscription !== null,
                'portalLoginUrl' => config('services.stripe.portal_login_url'),
                'organization' => config('organization'),
            ],
        );
    }
}
