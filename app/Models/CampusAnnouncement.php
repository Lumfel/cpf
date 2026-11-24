<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'campus_id',
    'published_by_user_id',
    'publisher_role',
    'template_key',
    'title',
    'body',
    'schedule_label',
    'location',
    'is_pinned',
    'published_at',
])]
class CampusAnnouncement extends Model
{
    protected function casts(): array
    {
        return [
            'is_pinned' => 'boolean',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Reusable PIO / Secretary announcement templates.
     *
     * @return array<string, array{key: string, label: string, default_title: string, default_body: string, default_schedule_label: string, default_location: string}>
     */
    public static function templates(): array
    {
        return [
            'general_assembly' => [
                'key' => 'general_assembly',
                'label' => 'General Assembly',
                'default_title' => 'CPF Campus General Assembly Notice',
                'default_body' => 'All verified peer facilitators are invited to attend our General Assembly. Please bring your Attendance QR ID for check-in and review the agenda ahead of time.',
                'default_schedule_label' => 'Friday • 3:00 PM – 5:00 PM',
                'default_location' => 'CHMSU Guidance & Counseling Hall',
            ],
            'workshop_reminder' => [
                'key' => 'workshop_reminder',
                'label' => 'Workshop Reminder',
                'default_title' => 'Peer Facilitation & Active Listening Workshop Reminder',
                'default_body' => 'Friendly reminder for our upcoming peer facilitation skills workshop. Kindly arrive 10 minutes early for QR check-in and bring a notebook for reflection exercises.',
                'default_schedule_label' => 'Wednesday • 1:30 PM – 4:00 PM',
                'default_location' => 'Testing & Peer Training Room',
            ],
            'dues_notice' => [
                'key' => 'dues_notice',
                'label' => 'Dues Notice',
                'default_title' => 'Semester Membership Dues Collection Reminder',
                'default_body' => 'Peer facilitators who have not yet settled their chapter membership dues for this academic term may coordinate with the campus Treasurer or settle via the approved campus payment channel.',
                'default_schedule_label' => 'Open Monday – Friday • 9:00 AM – 4:00 PM',
                'default_location' => 'CPF Chapter Desk / Online Ledger',
            ],
            'wellness_checkin' => [
                'key' => 'wellness_checkin',
                'label' => 'Wellness Check-in',
                'default_title' => 'Midterm Peer Wellness Check-in & Calm Drop-in Hours',
                'default_body' => 'Take a gentle pause this week. Our peer facilitators and campus Guidance Counselors have open Testing Room drop-in slots if you or a classmate need a quiet check-in.',
                'default_schedule_label' => 'Monday – Friday • Vacant Slots on Campus Hub',
                'default_location' => 'Campus Testing Room',
            ],
        ];
    }

    public function campus(): BelongsTo
    {
        return $this->belongsTo(Campus::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by_user_id');
    }
}
