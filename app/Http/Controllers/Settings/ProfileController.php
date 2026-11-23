<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/Profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->validated());

        if ($request->user()->isDirty('email')) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Upload or replace the authenticated member/officer/adviser's single profile picture.
     */
    public function updatePhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $user->verifiedRole() !== null,
            403,
            'Only approved CPF members, officers, and the Club Adviser can upload a profile picture.',
        );

        $request->validate([
            'photo' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
        ]);

        $oldPath = $user->profile_photo_path;
        $newPath = $request->file('photo')->store('profile-photos', 'local');

        $user->forceFill(['profile_photo_path' => $newPath])->save();

        if ($oldPath && $oldPath !== $newPath) {
            Storage::disk('local')->delete($oldPath);

            if (Storage::disk('public')->exists($oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }
        }

        return back()->with('status', 'Profile picture updated.');
    }

    /**
     * Remove the authenticated user's profile picture.
     */
    public function destroyPhoto(Request $request): RedirectResponse
    {
        $user = $request->user();

        abort_unless(
            $user && $user->verifiedRole() !== null,
            403,
            'Only approved CPF members, officers, and the Club Adviser can manage a profile picture.',
        );

        if ($user->profile_photo_path) {
            Storage::disk('local')->delete($user->profile_photo_path);

            if (Storage::disk('public')->exists($user->profile_photo_path)) {
                Storage::disk('public')->delete($user->profile_photo_path);
            }

            $user->forceFill(['profile_photo_path' => null])->save();
        }

        return back()->with('status', 'Profile picture removed.');
    }

    /**
     * Stream a campus member's profile picture to authorized same-campus viewers.
     */
    public function showPhoto(Request $request, User $targetUser): StreamedResponse
    {
        $viewer = $request->user();
        $isSelf = $viewer !== null && $viewer->id === $targetUser->id;
        $viewerCampusId = $viewer?->primaryMembership?->campus_id;
        $targetCampusId = $targetUser->primaryMembership?->campus_id;

        abort_unless(
            $isSelf || ($viewerCampusId !== null && $viewerCampusId === $targetCampusId),
            403,
            'You can only view profile pictures of members in your campus chapter.',
        );

        $path = $targetUser->profile_photo_path;
        $disk = null;

        if ($path && Storage::disk('local')->exists($path)) {
            $disk = 'local';
        } elseif ($path && Storage::disk('public')->exists($path)) {
            $disk = 'public';
        }

        abort_unless($disk !== null, 404);

        return Storage::disk($disk)->response($path, null, [
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    /**
     * Account deletion is disabled to protect CPF records and governance continuity.
     */
    public function destroy(Request $request): RedirectResponse
    {
        abort(403, 'Account deletion is currently disabled to protect CPF chapter records and governance continuity.');
    }
}
