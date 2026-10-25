<?php

namespace App\Console\Commands;

use App\Mail\TestEmail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Sends a test email right away (not through the queue), so a server's
 * mail settings can be checked before donors depend on them. With an
 * AhaSend sandbox credential, the message is recorded in the AhaSend
 * dashboard but not delivered.
 */
class SendTestEmail extends Command
{
    /**
     * @var string
     */
    protected $signature = 'mail:test {to : The address to send the test email to}';

    /**
     * @var string
     */
    protected $description = 'Send a test email to check the mail settings';

    public function handle(): int
    {
        $to = $this->argument('to');

        if (Validator::make(['to' => $to], ['to' => 'email'])->fails()) {
            $this->components->error("{$to} is not an email address.");

            return self::FAILURE;
        }

        $mailer = config('mail.default');

        $this->components->twoColumnDetail('Mailer', $mailer);

        if ($mailer === 'smtp') {
            $this->components->twoColumnDetail('Server', config('mail.mailers.smtp.host') . ':' . config('mail.mailers.smtp.port'));
        }

        $this->components->twoColumnDetail('From', config('mail.from.address'));
        $this->components->twoColumnDetail('Replies to', config('mail.reply_to.address') ?? 'the from address');

        try {
            Mail::to($to)->sendNow(new TestEmail(config('app.url')));
        } catch (Throwable $exception) {
            $this->components->error("Sending failed: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->components->info(match ($mailer) {
            'smtp' => "Sent to {$to}. With a sandbox credential, AhaSend records it in its dashboard without delivering it.",
            'log' => "Written to the log (storage/logs/laravel.log) instead of sent, since MAIL_MAILER is log.",
            default => "Sent to {$to} with the {$mailer} mailer.",
        });

        return self::SUCCESS;
    }
}
