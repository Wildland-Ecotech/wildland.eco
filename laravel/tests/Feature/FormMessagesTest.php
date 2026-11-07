<?php

namespace Tests\Feature;

use App\Http\Requests\AddContactDetailRequest;
use App\Http\Requests\CancelTrailerLoadRequest;
use App\Http\Requests\DeleteContactRequest;
use App\Http\Requests\LinkApplicationContactRequest;
use App\Http\Requests\LogContactActivityRequest;
use App\Http\Requests\MarkTrailerLoadDoneRequest;
use App\Http\Requests\SaveLocationsRequest;
use App\Http\Requests\SaveTrailerLoadRequest;
use App\Http\Requests\LoginRequest;
use App\Http\Requests\SaveContactRequest;
use App\Http\Requests\StartDonationRequest;
use App\Http\Requests\StoreLeafApplicationRequest;
use Illuminate\Foundation\Http\FormRequest;
use Tests\TestCase;

/**
 * Every message a visitor sees says what went wrong and what to do next
 * (see "Error messages" in docs/08-style-guide.md). Laravel uses a message
 * under a field's bare name for any of its rules without a message of their
 * own, so a field with one never shows Laravel's default wording.
 */
class FormMessagesTest extends TestCase
{
    public function test_every_form_field_has_a_message_of_its_own(): void
    {
        $requests = [
            new StartDonationRequest,
            new StoreLeafApplicationRequest,
            new LoginRequest,
            new SaveContactRequest,
            new AddContactDetailRequest,
            new DeleteContactRequest,
            new LinkApplicationContactRequest,
            new LogContactActivityRequest,
            new SaveLocationsRequest,
            new SaveTrailerLoadRequest,
            new CancelTrailerLoadRequest,
            new MarkTrailerLoadDoneRequest,
        ];

        foreach ($requests as $request) {
            $this->assertFieldsHaveMessages($request);
        }
    }

    private function assertFieldsHaveMessages(FormRequest $request): void
    {
        $messages = $request->messages();

        foreach (array_keys($request->rules()) as $field) {
            $this->assertArrayHasKey($field, $messages, class_basename($request) . " has no message for {$field}");
        }
    }
}
