<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\SaveTrailerLoadRequest;
use App\Models\LeafApplication;
use App\Models\TrailerLoad;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;

/**
 * Saving trailer loads chosen on the admin Map page (/admin/map?build=1).
 * MapController shows them: the Scheduled view lists them, and
 * /admin/map?load={id} opens one.
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

        $bags = LeafApplication::whereIn('id', $stops)->sum('estimated_bag_count');

        return redirect()->route('admin.map', ['load' => $load->id])->with(
            'status',
            "Saved the {$load->label()} load: " . count($stops) . ' ' . (count($stops) === 1 ? 'stop' : 'stops') . ', ' . number_format($bags) . ' bags.',
        );
    }
}
