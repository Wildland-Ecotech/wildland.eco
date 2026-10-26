<x-mail::message>
# Thank you for your donation

{{ filled($name) ? "Dear {$name}," : 'Hello,' }}

Thank you for your donation to {{ $organization['name'] }}. On behalf of the entire {{ $organization['name'] }} team, we're grateful for your support. Your donation supports the restoration work of our 80+ volunteers and dozens of landowners across Colorado, protecting communities from wildfire, drought, and flooding while restoring habitat for future generations.

Please keep this receipt for your tax records.

<x-mail::table>
| Donation receipt | |
|:-----------------|:-|
| Receipt number | {{ $receipt->receipt_number }} |
| Date | {{ $date }} |
| Amount | {{ $amount }} |
| Donation | {{ $kind }} |
</x-mail::table>

No goods or services were provided in exchange for this contribution.

{{ $organization['legal_name'] }} (“{{ $organization['name'] }}”) is a 501(c)(3) nonprofit organization. Your donation is tax-deductible to the extent allowed by law.

**{{ $organization['legal_name'] }}**<br>
@foreach ($organization['address'] as $line)
{{ $line }}<br>
@endforeach
EIN: {{ $organization['ein'] }}

@if ($recurring && filled($portalLoginUrl))
## Your recurring donation

You'll receive a receipt like this one for each payment. To update your payment method or cancel your recurring donation, sign in with this email address. Stripe, our payment processor, will email you a secure sign-in link.

<x-mail::button :url="$portalLoginUrl">
Manage Your Recurring Donation
</x-mail::button>
@endif

Questions about your donation? Reply to this email, or write to us at {{ $organization['email'] }}.

With gratitude,<br>
The {{ $organization['name'] }} team
</x-mail::message>
