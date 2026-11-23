<?php

namespace App\Models;

use App\Enums\MembershipStatus;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable([
    'campus_id',
    'title',
    'description',
    'location',
    'event_date',
    'start_time',
    'end_time',
    'status',
    'reflection_enabled',
    'pending_reflection_enabled',
    'reflection_requested_by_user_id',
    'reflection_requested_at',
    'created_by_user_id',
    'approved_by_user_id',
    'approved_at',
    'cancelled_by_user_id',
    'cancelled_at',
    'cancellation_reason',
    'activated_at',
    'completed_at',
])]
class CampusEvent extends Model
{
    protected function casts(): array
    {
        return [
            'event_date' => 'date',
            'reflection_enabled' => 'boolean',
            'pending_reflection_enabled' => 'boolean',
            'reflection_requested_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'activated_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by_user_id');
    }

    public function canceller(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_user_id');
    }

    public function reflectionRequester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reflection_requested_by_user_id');
    }

    public function attendances(): HasMany
    {
        return $this->hasMany(CampusEventAttendance::class);
    }

    public function reflections(): HasMany
    {
        return $this->hasMany(CampusEventReflection::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(CampusEventNotification::class);
    }

    public function startsAt(): CarbonInterface
    {
        return Carbon::parse($this->event_date->format('Y-m-d').' '.$this->start_time);
    }

    public function endsAt(): CarbonInterface
    {
        return Carbon::parse($this->event_date->format('Y-m-d').' '.$this->end_time);
    }

    public function effectiveStatus(?CarbonInterface $at = null): string
    {
        if ($this->status === 'cancelled') {
            return 'cancelled';
        }

        if ($this->status === 'pending_approval') {
            return 'pending_approval';
        }

        $now = $at ?? now();
        $startsAt = $this->startsAt();
        $endsAt = $this->endsAt();

        if ($now->lt($startsAt)) {
            return 'scheduled';
        }

        if ($now->betweenIncluded($startsAt, $endsAt)) {
            return 'active';
        }

        return 'completed';
    }

    public function isWithinOneDayCutoff(?CarbonInterface $at = null): bool
    {
        $now = $at ?? now();

        return $this->event_date->copy()->startOfDay()->lt($now->copy()->addDay()->startOfDay());
    }

    public function canEditSchedule(?CarbonInterface $at = null): bool
    {
        return in_array($this->effectiveStatus($at), ['pending_approval', 'scheduled'], true)
            && ! $this->isWithinOneDayCutoff($at);
    }

    public function syncLifecycleTimestamps(?CarbonInterface $at = null): void
    {
        if ($this->status !== 'approved') {
            return;
        }

        $now = $at ?? now();
        $effective = $this->effectiveStatus($now);

        if ($effective === 'active' && $this->activated_at === null) {
            $this->forceFill(['activated_at' => $now])->save();

            self::notifyCampusMembers(
                $this,
                'event_activated',
                "Activity Now Active: {$this->title}",
                "{$this->title} is now active ({$this->event_date->format('M d, Y')} • {$this->start_time}–{$this->end_time}). Officers can now scan Attendance QR codes for Log-In & Log-Out.",
            );
        } elseif ($effective === 'completed' && $this->completed_at === null) {
            $this->forceFill([
                'activated_at' => $this->activated_at ?? $this->startsAt(),
                'completed_at' => $now,
            ])->save();

            $completionMessage = $this->reflection_enabled
                ? "{$this->title} has ended. Post-event reflection is enabled — verified members may now write and seal their reflection in their Time Capsule."
                : "{$this->title} has ended. No post-event reflection is required for this activity.";

            self::notifyCampusMembers(
                $this,
                'event_completed',
                "Activity Completed: {$this->title}",
                $completionMessage,
            );
        }
    }

    public static function notifyCampusMembers(
        self $event,
        string $type,
        string $title,
        string $message,
        bool $advisersOnly = false,
    ): void {
        $recipientIds = CampusMembership::query()
            ->where('campus_id', $event->campus_id)
            ->where('status', MembershipStatus::Approved->value)
            ->when($advisersOnly, fn ($query) => $query->where('role', 'adviser'))
            ->pluck('user_id')
            ->unique()
            ->values();

        if ($recipientIds->isEmpty()) {
            return;
        }

        $now = now();
        $rows = $recipientIds->map(fn (int $userId): array => [
            'campus_id' => $event->campus_id,
            'campus_event_id' => $event->id,
            'user_id' => $userId,
            'type' => $type,
            'title' => $title,
            'message' => $message,
            'read_at' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        CampusEventNotification::query()->insert($rows);
    }
}
