<?php

namespace App\Reports;

use App\Models\Checkout;
use App\Models\Donation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Abandoned checkouts, for donations:abandoned and the admin pages: donors
 * who filled in the donate form and went to Stripe's payment page, but left
 * without paying, so the checkout expired. Shows whether each donor donated
 * later anyway.
 */
class AbandonedCheckoutReport
{
    /**
     * Checkouts abandoned in the last $days days, by when they were started,
     * oldest first, with their donor (including a soft-deleted one) and a
     * `donated_later` attribute: 1 when the donor has a donation we received,
     * paid after the checkout was started, else null.
     *
     * Abandoned means expired, or still started with Stripe's expiry time
     * passed. Checkouts that never reached Stripe, are still open, were paid,
     * or were soft-deleted are left out.
     *
     * @return Collection<int, Checkout>
     */
    public function list(int $days): Collection
    {
        return Checkout::query()
            ->select('checkouts.*')
            ->addSelect(['donated_later' => $this->donatedLater()])
            ->with(['user' => fn ($query) => $query->withTrashed()])
            ->where(fn (Builder $query) => $query
                ->where('status', Checkout::STATUS_EXPIRED)
                // Expired without the webhook saying so yet, such as during
                // an outage; the nightly sync catches up on these
                ->orWhere(fn (Builder $query) => $query
                    ->where('status', Checkout::STATUS_STARTED)
                    ->whereNotNull('gateway_session_id')
                    ->where('expires_at', '<', now())))
            ->where('created_at', '>=', now()->subDays($days))
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    /**
     * Whether the checkout's donor has a donation we received, paid after
     * the checkout was started, as a subquery: 1, or null.
     *
     * @return Builder<Donation>
     */
    private function donatedLater(): Builder
    {
        return Donation::query()
            ->received()
            ->selectRaw('1')
            ->whereColumn('donations.user_id', 'checkouts.user_id')
            ->whereColumn('donations.paid_at', '>=', 'checkouts.created_at')
            ->limit(1);
    }
}
