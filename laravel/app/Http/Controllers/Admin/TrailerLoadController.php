<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTrailerLoadRequest;
use App\Http\Requests\LogContactActivityRequest;
use App\Http\Requests\MarkTrailerLoadDoneRequest;
use App\Http\Requests\SaveTrailerLoadRequest;
use App\Models\ContactActivity;
use App\Models\LeafApplication;
use App\Models\TrailerLoad;
use App\Reports\MapReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Saving trailer loads chosen on the admin Map page (/admin/map?build=1),
 * editing a planned one (/admin/map?load={id}&edit=1), moving its stops up
 * or down the route, canceling it, and marking it done once it's been
 * picked up (/admin/map/loads/{id}/done). MapController shows them: they're
 * listed above the map, and /admin/map?load={id} opens one. Only a planned
 * load can be changed.
 */
class TrailerLoadController extends Controller
{
    /**
     * Save a load with its stops in the order chosen, as the admin, and
     * open it.
     */
    public function store(SaveTrailerLoadRequest $request): RedirectResponse
    {
        $stops = $request->stops();

        $load = DB::transaction(function () use ($request, $stops) {
            $load = new TrailerLoad($request->safe()->only(['planned_on', 'capacity']));
            $load->forceFill(['created_by' => $request->user()->id])->save();

            foreach ($stops as $index => $id) {
                $load->applications()->attach($id, ['position' => $index + 1]);
            }

            return $load;
        });

        return $this->saved($load);
    }

    /**
     * Save a planned load's date, capacity, and stops: its stops still
     * chosen keep their order, new ones go at the end, and those taken out
     * are deleted from it, back to needing scheduling. A stop that's no
     * longer pending, such as one whose pickup was logged on its own, stays.
     */
    public function update(SaveTrailerLoadRequest $request, TrailerLoad $load): RedirectResponse
    {
        if (! $load->isPlanned()) {
            return $this->unchangeable($load);
        }

        $chosen = $request->stops();

        DB::transaction(function () use ($request, $load, $chosen) {
            $load->fill($request->safe()->only(['planned_on', 'capacity']))->save();

            $existing = $load->applications()->get();
            $keep = $existing
                ->filter(fn (LeafApplication $application) => in_array($application->id, $chosen, true)
                    || $application->trashed()
                    || $application->status !== LeafApplication::STATUS_PENDING)
                ->pluck('id')
                ->all();
            $existingIds = $existing->pluck('id')->all();
            $order = [...$keep, ...array_values(array_diff($chosen, $existingIds))];

            $load->applications()->detach(array_values(array_diff($existingIds, $keep)));

            foreach ($order as $index => $id) {
                in_array($id, $existingIds, true)
                    ? $load->applications()->updateExistingPivot($id, ['position' => $index + 1])
                    : $load->applications()->attach($id, ['position' => $index + 1]);
            }
        });

        return $this->saved($load);
    }

    /**
     * Move a planned load's stop one place up or down the route, and go
     * back to its stops.
     */
    public function move(TrailerLoad $load, int $application, string $direction): RedirectResponse
    {
        if (! $load->isPlanned()) {
            return $this->unchangeable($load);
        }

        DB::transaction(function () use ($load, $application, $direction) {
            $ids = $load->applications()->pluck('leaf_applications.id')->map(fn ($id) => (int) $id)->all();
            $index = array_search($application, $ids, true);
            abort_if($index === false, 404);

            $other = $direction === 'up' ? $index - 1 : $index + 1;

            if (isset($ids[$other])) {
                [$ids[$index], $ids[$other]] = [$ids[$other], $ids[$index]];
            }

            // Numbered from 1 again, so the positions always run in order
            foreach ($ids as $position => $id) {
                $load->applications()->updateExistingPivot($id, ['position' => $position + 1]);
            }
        });

        return redirect()->to(route('admin.map', ['load' => $load->id]) . '#stops');
    }

    /**
     * Cancel a planned load, as the admin. It's kept, marked canceled, and
     * its pending stops need scheduling again.
     */
    public function cancel(CancelTrailerLoadRequest $request, TrailerLoad $load): RedirectResponse
    {
        if (! $load->isPlanned()) {
            return $this->unchangeable($load);
        }

        $load->forceFill([
            'status' => TrailerLoad::STATUS_CANCELED,
            'finished_at' => now(),
            'finished_by' => $request->user()->id,
        ])->save();

        $pending = $load->applications()->where('status', LeafApplication::STATUS_PENDING)->count();

        return redirect()->route('admin.map')->with(
            'status',
            "Canceled the {$load->label()} load." . ($pending > 0 ? " Its {$pending} " . ($pending === 1 ? 'stop needs' : 'stops need') . ' scheduling again.' : ''),
        );
    }

    /**
     * The Mark Done page for a planned load: when, and each stop still
     * pending, checked, with its estimated bags filled in. A stop not linked
     * to a contact is listed without a box, since its pickup has nowhere to
     * go until it's linked.
     */
    public function done(TrailerLoad $load, MapReport $report): View|RedirectResponse
    {
        if (! $load->isPlanned()) {
            return $this->unchangeable($load);
        }

        $loggable = $load->loggableApplications()->pluck('id')->all();
        $stops = $report->stops($load)
            ->filter(fn (array $stop) => ! $stop['application']->trashed() && $stop['application']->status === LeafApplication::STATUS_PENDING)
            ->values();
        // Every stop checked to start with, or as the form came back
        $old = session()->hasOldInput() ? array_map('strval', array_filter((array) old('stops', []), 'is_scalar')) : null;

        return view('pages.admin.loads.done', [
            'load' => $load,
            'summary' => "{$load->label()} load · " . $stops->count() . ' ' . Str::plural('stop', $stops->count()) . ' to log',
            'finishedAt' => old('finished_at', Carbon::now(LogContactActivityRequest::TIMEZONE)->format(LogContactActivityRequest::TIME_FORMAT)),
            'stops' => $stops->map(function (array $stop) use ($loggable, $old) {
                $application = $stop['application'];
                $id = $application->id;

                return [
                    'application' => $id,
                    'position' => $stop['position'],
                    'name' => $stop['contact']?->name ?? $application->name,
                    'address' => $stop['address']?->value ?? $application->address,
                    'estimate' => $application->estimated_bag_count,
                    'bags' => (string) old("bags.{$id}", (string) $application->estimated_bag_count),
                    'loggable' => in_array($id, $loggable, true),
                    'checked' => $old === null || in_array((string) $id, $old, true),
                    'url' => route('admin.leaves.show', $id),
                ];
            })->all(),
            'skipped' => $load->applications()->count() - $stops->count(),
        ]);
    }

    /**
     * Mark a planned load done, as the admin: log a pickup on the contact's
     * timeline for each stop checked, which marks its application complete
     * (ContactActivity), and keep it on the stop. Stops left unchecked stay
     * pending and need scheduling again.
     */
    public function finish(MarkTrailerLoadDoneRequest $request, TrailerLoad $load): RedirectResponse
    {
        if (! $load->isPlanned()) {
            return $this->unchangeable($load);
        }

        $pickups = $request->pickups();
        $happenedAt = $request->finishedAt();

        DB::transaction(function () use ($request, $load, $pickups, $happenedAt) {
            $applications = $load->loggableApplications()->keyBy('id');

            foreach ($pickups as $id => $bags) {
                $activity = new ContactActivity([
                    'type' => ContactActivity::TYPE_PICKUP,
                    'happened_at' => $happenedAt,
                    'bag_count' => $bags,
                    'leaf_application_id' => $id,
                ]);
                $activity->forceFill([
                    'contact_id' => $applications[$id]->contact_id,
                    'logged_by' => $request->user()->id,
                ])->save();

                $load->applications()->updateExistingPivot($id, ['contact_activity_id' => $activity->id]);
            }

            $load->forceFill([
                'status' => TrailerLoad::STATUS_DONE,
                'finished_at' => now(),
                'finished_by' => $request->user()->id,
            ])->save();
        });

        $count = count($pickups);
        $left = $load->applications()->where('leaf_applications.status', LeafApplication::STATUS_PENDING)->whereNull('leaf_applications.deleted_at')->count();
        $logged = $count === 0
            ? 'Marked the ' . $load->label() . ' load done without logging any pickups.'
            : 'Logged ' . $count . ' ' . Str::plural('pickup', $count) . ', ' . number_format(array_sum($pickups)) . ' ' . Str::plural('bag', array_sum($pickups)) . ', and marked the ' . $load->label() . ' load done.';

        return redirect()->route('admin.map', ['load' => $load->id])->with(
            'status',
            $logged . ($left > 0 ? " {$left} " . ($left === 1 ? 'stop needs' : 'stops need') . ' scheduling again.' : ''),
        );
    }

    /**
     * Back to a saved load, saying what's in it.
     */
    private function saved(TrailerLoad $load): RedirectResponse
    {
        $applications = $load->applications()->get();

        return redirect()->route('admin.map', ['load' => $load->id])->with(
            'status',
            "Saved the {$load->label()} load: " . $applications->count() . ' ' . ($applications->count() === 1 ? 'stop' : 'stops') . ', ' . number_format($applications->sum('estimated_bag_count')) . ' bags.',
        );
    }

    /**
     * Back to a load that's done or canceled, which can't be changed.
     */
    private function unchangeable(TrailerLoad $load): RedirectResponse
    {
        return redirect()->route('admin.map', ['load' => $load->id])->with(
            'status',
            'This load is ' . strtolower(TrailerLoad::STATUS_LABELS[$load->status]) . ', so it can’t be changed.',
        );
    }
}
