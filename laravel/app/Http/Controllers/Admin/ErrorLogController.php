<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Admin\Traits\FiltersByPeriod;
use App\Http\Controllers\Controller;
use App\Models\FormSubmission;
use App\Reports\FormSubmissionReport;
use App\Reports\Traits\DescribesFormSubmissions;
use App\Reports\Traits\FormatsCsv;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\View\View;

/**
 * The admin Error Logs pages (/admin/errors): submissions of the donate and
 * leaf application forms that didn't go through, with filters and a CSV
 * download, and one submission in full by its reference. They show the same
 * submissions and details as submissions:list, from FormSubmissionReport
 * and DescribesFormSubmissions. For now the log holds form submissions; it
 * may grow into general error reporting.
 */
class ErrorLogController extends Controller
{
    use DescribesFormSubmissions, FiltersByPeriod, FormatsCsv;

    /**
     * The form filters' labels, keyed by FormSubmission::FORMS.
     */
    public const FORM_LABELS = [
        FormSubmission::FORM_DONATION => 'Donation',
        FormSubmission::FORM_LEAF_APPLICATION => 'Leaf application',
    ];

    /**
     * The list's columns that make up a submission's line on a phone
     * (submitted, name, and what happened). No column is a number.
     */
    public const SUMMARY_COLUMNS = [1, 4, 3];

    /**
     * The list of submissions, filtered by the query string.
     */
    public function index(Request $request, FormSubmissionReport $report): View
    {
        $filters = $this->filters($request);
        $submissions = $report->list(self::PERIODS[$filters['period']], $filters['forms'], $filters['all']);
        $count = $submissions->count();

        return view('pages.admin.errors.index', [
            'filters' => $filters,
            'summary' => $this->periodSummary($filters['period'], $count, $this->formSubmissionListName($filters['all'], $count)),
            'headers' => self::FORM_SUBMISSION_TABLE_HEADERS,
            'rows' => $submissions->map(function (FormSubmission $submission) {
                $cells = $this->formSubmissionTableRow($submission);

                return [
                    'cells' => $cells,
                    'summary' => array_values(array_filter([$this->shortDate($submission->created_at), $cells[4], $cells[3]])),
                    'url' => route('admin.errors.show', $submission->reference),
                ];
            })->all(),
            'csvUrl' => route('admin.errors.csv', $this->query($filters)),
        ]);
    }

    /**
     * The same list as a CSV download, with every detail, like
     * `submissions:list --csv`.
     */
    public function download(Request $request, FormSubmissionReport $report): Response
    {
        $filters = $this->filters($request);
        $submissions = $report->list(self::PERIODS[$filters['period']], $filters['forms'], $filters['all']);

        $filename = 'error-logs-' . now(config('organization.timezone'))->format('Y-m-d') . '.csv';

        return response($this->csv($this->formSubmissionCsvHeader(), $submissions->map($this->formSubmissionCsvRow(...))), 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * One submission in full, by its reference, whatever its outcome.
     */
    public function show(string $reference, FormSubmissionReport $report): View
    {
        $submission = $report->find($reference);

        abort_if($submission === null, 404);

        return view('pages.admin.errors.show', [
            'submission' => $submission,
            'fields' => [
                'Submitted' => $this->localTime($submission->created_at),
                ...$this->formSubmissionFields($submission),
            ],
        ]);
    }

    /**
     * The list's filters from the query string. Anything unexpected falls
     * back to the defaults, since the form itself can only send valid values.
     *
     * @return array{period: string, forms: array<int, string>, all: bool}
     */
    private function filters(Request $request): array
    {
        $forms = $request->query('form');

        return [
            'period' => $this->periodFilter($request),
            'forms' => is_array($forms) ? array_values(array_intersect(FormSubmission::FORMS, $forms)) : [],
            'all' => $request->query('all') === '1',
        ];
    }

    /**
     * The query string for the filters, for the CSV download link.
     *
     * @param  array{period: string, forms: array<int, string>, all: bool}  $filters
     * @return array<string, mixed>
     */
    private function query(array $filters): array
    {
        return array_filter([
            'period' => $filters['period'],
            'form' => $filters['forms'],
            'all' => $filters['all'] ? '1' : null,
        ]);
    }
}
