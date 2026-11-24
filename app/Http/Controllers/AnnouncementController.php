<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Models\CampusAnnouncement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AnnouncementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user !== null && $membership !== null && $user->verifiedRole() !== null,
            403,
            'Only verified campus members may view campus announcements.',
        );

        $announcements = CampusAnnouncement::query()
            ->with('publisher:id,name')
            ->where('campus_id', $membership->campus_id)
            ->orderByDesc('is_pinned')
            ->orderByDesc('published_at')
            ->orderByDesc('id')
            ->limit(25)
            ->get()
            ->map(fn (CampusAnnouncement $item) => self::formatAnnouncement($item))
            ->values();

        return response()->json([
            'templates' => array_values(CampusAnnouncement::templates()),
            'announcements' => $announcements,
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $role = $user?->verifiedRole();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canPublishAnnouncements() && $role !== null && $membership !== null,
            403,
            'Only the Public Information Officer (PIO), Secretary, or Club Adviser can publish campus announcements.',
        );

        $templates = CampusAnnouncement::templates();

        $validated = $request->validate([
            'template_key' => ['required', Rule::in(array_keys($templates))],
            'title' => ['required', 'string', 'max:180'],
            'body' => ['required', 'string', 'max:3000'],
            'schedule_label' => ['nullable', 'string', 'max:140'],
            'location' => ['nullable', 'string', 'max:140'],
            'is_pinned' => ['nullable', 'boolean'],
        ]);

        $announcement = CampusAnnouncement::create([
            'campus_id' => $membership->campus_id,
            'published_by_user_id' => $user->id,
            'publisher_role' => $role->value,
            'template_key' => $validated['template_key'],
            'title' => trim((string) $validated['title']),
            'body' => trim((string) $validated['body']),
            'schedule_label' => isset($validated['schedule_label']) && filled($validated['schedule_label'])
                ? trim((string) $validated['schedule_label'])
                : null,
            'location' => isset($validated['location']) && filled($validated['location'])
                ? trim((string) $validated['location'])
                : null,
            'is_pinned' => (bool) ($validated['is_pinned'] ?? false),
            'published_at' => now(),
        ]);

        $announcement->load('publisher:id,name');

        return response()->json([
            'status' => 'published',
            'announcement' => self::formatAnnouncement($announcement),
        ], 201);
    }

    public function destroy(Request $request, CampusAnnouncement $announcement): JsonResponse
    {
        $user = $request->user();
        $membership = $user?->primaryMembership;

        abort_unless(
            $user?->canPublishAnnouncements() && $membership?->campus_id === $announcement->campus_id,
            403,
            'Only the campus PIO, Secretary, or Club Adviser can remove announcements.',
        );

        $announcement->delete();

        return response()->json(['status' => 'deleted']);
    }

    /**
     * @return array<string, mixed>
     */
    public static function formatAnnouncement(CampusAnnouncement $item): array
    {
        $templates = CampusAnnouncement::templates();
        $roleEnum = CpfRole::tryFrom($item->publisher_role);

        return [
            'id' => $item->id,
            'template_key' => $item->template_key,
            'template_label' => $templates[$item->template_key]['label'] ?? 'Announcement',
            'title' => $item->title,
            'body' => $item->body,
            'schedule_label' => $item->schedule_label,
            'location' => $item->location,
            'is_pinned' => (bool) $item->is_pinned,
            'publisher_name' => $item->publisher?->name ?? 'CPF Officer',
            'publisher_role' => $item->publisher_role,
            'publisher_role_label' => $roleEnum?->label() ?? 'CPF Officer',
            'published_at' => $item->published_at?->toDayDateTimeString(),
            'published_diff' => $item->published_at?->diffForHumans(),
        ];
    }
}
