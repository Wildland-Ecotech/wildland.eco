<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\LoginAttempt;
use App\Reports\SignInReport;
use App\Reports\Traits\FormatsTimes;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The admin Sign-Ins page (/admin/sign-ins): every attempt to sign in,
 * newest first, from SignInReport, for spotting someone trying to get in.
 * It's opened from Error Logs, so it has no link of its own in the admin
 * menu. Each IP address links to the attempts from it.
 */
class SignInController extends Controller
{
    use FormatsTimes;

    public const SHOW_ALL = 'all';

    public const SHOW_FAILED = 'failed';

    public const SHOW_CHOICES = [
        self::SHOW_ALL => 'All attempts',
        self::SHOW_FAILED => 'Failed only',
    ];

    public const HEADERS = ['When', 'Email', 'Outcome', 'IP address', 'Browser'];

    /**
     * The columns that make up an attempt's line on a phone (when, email,
     * and outcome). No column is a number.
     */
    public const SUMMARY_COLUMNS = [0, 1, 2];

    /**
     * The longest search the page takes; anything longer is cut short.
     */
    public const MAX_SEARCH_LENGTH = 100;

    public function index(Request $request, SignInReport $report): View
    {
        $search = $request->query('q');
        $search = is_string($search) ? Str::limit(trim($search), self::MAX_SEARCH_LENGTH, '') : '';
        $show = $request->query('show') === self::SHOW_FAILED ? self::SHOW_FAILED : self::SHOW_ALL;
        $failedOnly = $show === self::SHOW_FAILED;

        $attempts = $report->list($failedOnly, $search);
        $total = $attempts->count() < SignInReport::LIST_LIMIT ? $attempts->count() : $report->count($failedOnly, $search);

        return view('pages.admin.sign-ins', [
            'show' => $show,
            'search' => $search,
            'notice' => $report->notice(),
            'summary' => $this->summary($attempts->count(), $total, $failedOnly, $search),
            'headers' => self::HEADERS,
            'rows' => $attempts->map(fn (LoginAttempt $attempt) => [
                'cells' => [
                    $this->listTime($attempt->created_at),
                    (string) $attempt->email,
                    ['text' => $attempt->label(), 'status' => $attempt->succeeded() ? 'success' : 'failure'],
                    // Every attempt from the same address, keeping the Show choice
                    ['text' => $attempt->ip_address, 'url' => route('admin.sign-ins', array_filter(['show' => $failedOnly ? self::SHOW_FAILED : null, 'q' => $attempt->ip_address]))],
                    $attempt->browser(),
                ],
                'summary' => [$this->listTime($attempt->created_at), $attempt->email ?: 'No email', $attempt->label()],
                'status' => $attempt->succeeded() ? 'success' : 'failure',
                'statusLabel' => $attempt->succeeded() ? 'Succeeded' : 'Failed',
            ])->all(),
        ]);
    }

    /**
     * Such as "6 sign-in attempts, newest first.", "2 failed sign-in
     * attempts matching “203.0.113.77”, newest first.", or "The newest 500
     * of 1,234 sign-in attempts."
     */
    private function summary(int $shown, int $total, bool $failedOnly, string $search): string
    {
        $kind = $failedOnly ? 'failed sign-in' : 'sign-in';
        $matching = $search !== '' ? " matching “{$search}”" : '';

        return match (true) {
            $total === 0 && $search !== '' => "No {$kind} attempts{$matching}.",
            $total === 0 => "No {$kind} attempts yet.",
            $shown < $total => 'The newest ' . number_format($shown) . ' of ' . number_format($total) . " {$kind} attempts{$matching}.",
            default => number_format($total) . ' ' . $kind . ' ' . Str::plural('attempt', $total) . "{$matching}, newest first.",
        };
    }
}
