<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Support\Facades\DB;

/**
 * A trailer load of leaf pickups, planned on the admin Map page: the day
 * it's planned for, how many bags the trailer holds, and its stops, the
 * pickup applications in the order of the admin's route
 * (`trailer_load_stops`). A pending pickup is in only one planned load at a
 * time. Planned until it's marked done or canceled; only a planned load's
 * stops can be changed, and a stop taken out is deleted from it, leaving
 * its application as it was. Its stop list shows names, addresses, phone
 * numbers, and notes, to follow on a phone.
 */
#[Fillable([
    'planned_on',
    'capacity',
])]
class TrailerLoad extends Model
{
    public const STATUS_PLANNED = 'planned';

    public const STATUS_DONE = 'done';

    public const STATUS_CANCELED = 'canceled';

    public const STATUSES = [self::STATUS_PLANNED, self::STATUS_DONE, self::STATUS_CANCELED];

    public const STATUS_LABELS = [
        self::STATUS_PLANNED => 'Planned',
        self::STATUS_DONE => 'Done',
        self::STATUS_CANCELED => 'Canceled',
    ];

    /**
     * Filled in when starting a load.
     */
    public const DEFAULT_CAPACITY = 80;

    /**
     * From this share of the capacity, a load is nearly full: its progress
     * bar turns from green to yellow, and red once it's over.
     */
    public const NEARLY_FULL = 0.9;

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'status' => self::STATUS_PLANNED,
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'planned_on' => 'date',
            'capacity' => 'integer',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * The applications of its stops, in route order, each with its
     * `position` on the pivot. Deleted applications are kept, so a load
     * always shows what was planned.
     *
     * @return BelongsToMany<LeafApplication, $this>
     */
    public function applications(): BelongsToMany
    {
        return $this->belongsToMany(LeafApplication::class, 'trailer_load_stops')
            ->withPivot(['id', 'position', 'contact_activity_id'])
            ->withTimestamps()
            ->withTrashed()
            ->orderByPivot('position');
    }

    /**
     * The admin who saved it.
     *
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by')->withTrashed();
    }

    /**
     * Loads still planned.
     */
    #[Scope]
    protected function planned(Builder $query): void
    {
        $query->where('status', self::STATUS_PLANNED);
    }

    /**
     * The IDs of the applications in a planned load, which can't be added
     * to another.
     *
     * @return list<int>
     */
    public static function scheduledApplicationIds(): array
    {
        return DB::table('trailer_load_stops')
            ->join('trailer_loads', 'trailer_loads.id', '=', 'trailer_load_stops.trailer_load_id')
            ->where('trailer_loads.status', self::STATUS_PLANNED)
            ->pluck('trailer_load_stops.leaf_application_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Whether it's still planned, so its stops can be changed.
     */
    public function isPlanned(): bool
    {
        return $this->status === self::STATUS_PLANNED;
    }

    /**
     * The day it's planned for, such as "Sun, Nov 8".
     */
    public function label(): string
    {
        return $this->planned_on->format('D, M j');
    }

    /**
     * How full a number of bags makes a load of this capacity, for its
     * progress bar: the share filled (up to 100), its tone (`good` in green,
     * `full` in yellow from NEARLY_FULL, `over` in red past the capacity),
     * and words such as "3 stops · 70 of 80 bags · 10 bags of room".
     *
     * @return array{percent: int, tone: string, label: string}
     */
    public static function progress(int $stops, int $bags, int $capacity): array
    {
        $share = $capacity > 0 ? $bags / $capacity : ($bags > 0 ? 2 : 0);
        $room = $capacity - $bags;

        return [
            'percent' => (int) round(min($share, 1) * 100),
            'tone' => match (true) {
                $share > 1 => 'over',
                $share >= self::NEARLY_FULL => 'full',
                default => 'good',
            },
            'label' => $stops . ' ' . ($stops === 1 ? 'stop' : 'stops') . ' · ' . number_format($bags) . ' of ' . number_format($capacity) . ' bags · ' . match (true) {
                $room < 0 => number_format(-$room) . ' over',
                $room === 0 => 'Full',
                default => number_format($room) . ' ' . ($room === 1 ? 'bag' : 'bags') . ' of room',
            },
        ];
    }
}
