<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\CancelTrailerLoadRequest;
use App\Http\Requests\SaveTrailerLoadRequest;
use App\Models\LeafApplication;
use App\Models\TrailerLoad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Saving trailer loads chosen on the admin Map page (/admin/map?build=1),
 * editing a planned one (/admin/map?load={id}&edit=1), moving its stops up
 * or down the route, and canceling it. MapController shows them: they're
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
