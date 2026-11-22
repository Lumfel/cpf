<?php

namespace App\Http\Controllers;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\OfficerGcChannel;
use App\Models\PeerQuote;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class FirstBootSetupController extends Controller
{
    public static function needsAdviserSetup(): bool
    {
        try {
            return CampusMembership::query()
                ->where('role', CpfRole::Adviser->value)
                ->where('status', MembershipStatus::Approved->value)
                ->doesntExist();
        } catch (\Throwable) {
            return false;
        }
    }

    public function store(Request $request): RedirectResponse
    {
        abort_unless(
            self::needsAdviserSetup(),
            403,
            'First-boot setup is locked because a Club Adviser already exists.',
        );

        $validated = $request->validate([
            'campus_id' => ['nullable', 'integer', 'exists:campuses,id'],
            'campus_name' => ['nullable', 'string', 'max:160'],
            'academic_year' => ['required', 'string', 'max:20'],
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:50'],
            'last_name' => ['required', 'string', 'max:100'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
            'sex' => ['required', Rule::in(['Male', 'Female'])],
            'cellphone_number' => ['nullable', 'string', 'max:30'],
        ]);

        [$adviser, $campus, $fullName] = DB::transaction(function () use ($validated): array {
            abort_unless(
                self::needsAdviserSetup(),
                403,
                'First-boot setup is locked because a Club Adviser already exists.',
            );

            $campus = isset($validated['campus_id']) && $validated['campus_id']
                ? Campus::query()->find($validated['campus_id'])
                : Campus::query()->where('is_active', true)->orderBy('id')->first();

            if (! $campus) {
                $campus = Campus::query()->orderBy('id')->first();
            }

            $customCampusName = trim((string) ($validated['campus_name'] ?? ''));

            if (! $campus) {
                $campus = Campus::create([
                    'code' => 'ALIJIS',
                    'name' => $customCampusName !== '' ? $customCampusName : 'CHMSU Alijis Campus',
                    'slug' => 'alijis',
                    'current_academic_year' => trim((string) $validated['academic_year']),
                    'gc_group_name' => 'CPF Official Peer GC (AY '.trim((string) $validated['academic_year']).')',
                    'is_active' => true,
                ]);
            }

            $firstName = trim((string) $validated['first_name']);
            $middleName = isset($validated['middle_name']) ? trim((string) $validated['middle_name']) : null;
            $lastName = trim((string) $validated['last_name']);
            $fullName = CampusMembership::formatLastFirstMi($firstName, $middleName !== '' ? $middleName : null, $lastName);
            $academicYear = trim((string) $validated['academic_year']);
            $email = strtolower(trim((string) $validated['email']));
            $phone = trim((string) ($validated['cellphone_number'] ?? ''));

            $campus->update([
                'name' => $customCampusName !== '' ? $customCampusName : $campus->name,
                'adviser_name' => $fullName,
                'current_academic_year' => $academicYear,
                'gc_group_name' => $campus->gc_group_name ?: "CPF {$campus->code} Official Peer GC (AY {$academicYear})",
                'is_active' => true,
            ]);

            CampusRoom::updateOrCreate(
                [
                    'campus_id' => $campus->id,
                    'is_single_allowed_room' => true,
                ],
                [
                    'room_code' => 'TESTING_ROOM',
                    'room_name' => 'Testing Room',
                    'capacity' => 6,
                ],
            );

            CampusCounselor::ensureDefaultCounselorsForCampus($campus->id);

            OfficerGcChannel::updateOrCreate(
                [
                    'campus_id' => $campus->id,
                    'academic_year' => $academicYear,
                ],
                [
                    'channel_key' => sprintf('campus.%s.officers-gc.%s', strtolower($campus->code), $academicYear),
                    'is_active' => true,
                ],
            );

            $adviser = new User([
                'name' => $fullName,
                'email' => $email,
                'password' => Hash::make((string) $validated['password']),
                'must_change_password' => false,
            ]);
            $adviser->email_verified_at = now();
            $adviser->save();

            CampusMembership::create([
                'user_id' => $adviser->id,
                'campus_id' => $campus->id,
                'role' => CpfRole::Adviser,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $email,
                'first_name' => $firstName,
                'middle_name' => $middleName !== '' ? $middleName : null,
                'last_name' => $lastName,
                'full_name_last_first_mi' => $fullName,
                'course' => 'Guidance Services',
                'year_level' => 'Faculty',
                'section' => 'Adviser',
                'course_year_section' => 'Guidance Services / Adviser',
                'sex' => $validated['sex'],
                'gender_identity' => $validated['sex'] === 'Male' ? 'Man' : 'Woman',
                'birthdate' => '1985-01-01',
                'active_email' => $email,
                'facebook_profile_url' => 'https://www.facebook.com/chmsu.cpf.adviser',
                'active_fb_account' => $fullName,
                'cellphone_number' => $phone !== '' ? $phone : '0917-000-0000',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $adviser->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $adviser->id,
                'dues_confirmed_at' => now(),
            ]);

            if (PeerQuote::query()->where('is_approved', true)->doesntExist()) {
                PeerQuote::create([
                    'campus_id' => $campus->id,
                    'submitted_by_user_id' => $adviser->id,
                    'quote_text' => 'Take a breath. Your presence in this community matters more than perfection.',
                    'author_name' => 'Circle of Peer Facilitators',
                    'mood_tag' => 'Calm',
                    'is_approved' => true,
                ]);
            }

            return [$adviser, $campus, $fullName];
        });

        Auth::login($adviser, true);
        $request->session()->regenerate();

        return to_route('dashboard')
            ->with('status', "First-boot setup complete! Welcome, {$fullName} — you are now signed in as Club Adviser for {$campus->name}.");
    }
}
