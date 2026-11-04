<?php

namespace App\Console\Commands;

use App\Console\Traits\ListsRecords;
use App\Models\Donation;
use App\Payments\Stripe\StripeGateway;
use App\Reports\Traits\DescribesDonations;
use Illuminate\Console\Command;

/**
 * Shows one donation in full, by its ID from donations:list or its Stripe
 * payment ID. A deleted donation is shown too, marked as deleted, since it
 * was asked for by ID. The output contains the donor's name and email
 * address: never paste it into issues, chat, or the repository.
 */
class ViewDonation extends Command
{
    use DescribesDonations, ListsRecords;

    /**
     * @var string
     */
    protected $signature = 'donations:view
        {donation : The donation\'s ID from donations:list, or its Stripe payment ID (pi_...)}';

    /**
     * @var string
     */
    protected $description = 'Show one donation in full';

    public function handle(): int
    {
        $id = (string) $this->argument('donation');

        if (! ctype_digit($id) && ! preg_match('/^pi_[A-Za-z0-9]+$/D', $id)) {
            $this->components->error('Enter a donation ID from donations:list, such as 12, or a Stripe payment ID starting with pi_.');

            return self::INVALID;
        }

        $donation = ctype_digit($id)
            ? Donation::withTrashed()->with($this->donationRelations())->find((int) $id)
            : Donation::findByGatewayId(StripeGateway::NAME, $id)?->load($this->donationRelations());

        if (! $donation) {
            $this->components->error("No donation has the ID {$id}. Check the ID in donations:list, with --all for pending and failed payments.");

            return self::FAILURE;
        }

        $this->writeRecord("<options=bold>Donation {$donation->id}</>", [
            'Payment ID' => $donation->gateway_payment_id,
            'Date' => $this->localTime($this->donationMadeAt($donation)),
            ...$this->donationFields($donation),
        ]);

        if ($donation->wasReceived() && $donation->fee_cents === null) {
            $this->newLine();
            $this->components->warn("Stripe's fee isn't recorded yet. Running stripe:sync again records it once Stripe has it.");
        }

        if ($donation->trashed()) {
            $this->newLine();
            $this->components->warn('This donation was deleted on ' . $this->localTime($donation->deleted_at) . ', so donations:list and reports leave it out.');
        }

        return self::SUCCESS;
    }
}
