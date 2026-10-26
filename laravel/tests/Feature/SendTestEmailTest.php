<?php

namespace Tests\Feature;

use App\Mail\TestEmail;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendTestEmailTest extends TestCase
{
    public function test_a_test_email_is_sent_right_away(): void
    {
        Mail::fake();
        config(['mail.from.address' => 'no-reply@from.wildland.eco']);

        $this->artisan('mail:test', ['to' => 'rachel@example.com'])
            ->expectsOutputToContain('no-reply@from.wildland.eco')
            ->assertSuccessful();

        Mail::assertSent(TestEmail::class, fn (TestEmail $mail) => $mail->hasTo('rachel@example.com'));
        Mail::assertNothingQueued();
    }

    public function test_the_test_email_says_where_it_came_from(): void
    {
        config(['app.url' => 'https://stage.wildland.eco']);

        $mail = new TestEmail(config('app.url'));

        $mail->assertHasSubject('Test email from https://stage.wildland.eco');
        $mail->assertSeeInText('If you received it, this server can send email.');
    }

    public function test_replies_go_to_the_contact_mailbox_and_tls_is_required(): void
    {
        $this->assertSame('contact@wildland.eco', config('mail.reply_to.address'));
        $this->assertTrue(config('mail.mailers.smtp.require_tls'));
    }

    public function test_an_invalid_address_is_refused(): void
    {
        Mail::fake();

        $this->artisan('mail:test', ['to' => 'not-an-email'])->assertFailed();

        Mail::assertNothingSent();
    }

    public function test_a_sending_failure_is_shown(): void
    {
        Mail::shouldReceive('to->sendNow')->andThrow(new \RuntimeException('Connection refused'));

        $this->artisan('mail:test', ['to' => 'rachel@example.com'])
            ->expectsOutputToContain('Sending failed: Connection refused')
            ->assertFailed();
    }
}
