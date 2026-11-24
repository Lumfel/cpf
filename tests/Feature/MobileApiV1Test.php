<?php

namespace Tests\Feature;

use App\Actions\Security\IssueMemberPasskey;
use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusEvent;
use App\Models\CampusEventAttendance;
use App\Models\CampusMembership;
use App\Models\PeerQuote;
use App\Models\User;
use App\Services\ClubTenureService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileApiV1Test extends TestCase
{
    use RefreshDatabase;

    private Campus $alijis;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alijis = Campus::create([
            'code' => 'ALIJIS',
            'name' => 'CHMSU Alijis Campus',
            'slug' => 'alijis',
            'current_academic_year' => '2026-2027',
            'adviser_name' => 'Montemayor, Elena V.',
            'gc_group_name' => 'CPF Alijis Official Peer GC',
            'is_active' => true,
        ]);
    }

    private function createVerifiedMember(
        Campus $campus,
        CpfRole $role,
        string $name,
        bool $mustChangePassword = false,
    ): array {
        $user = User::factory()->create([
            'name' => $name,
            'password' => Hash::make('SecretPass2026!'),
            'must_change_password' => $mustChangePassword,
        ]);

        $membership = CampusMembership::create([
            'user_id' => $user->id,
            'campus_id' => $campus->id,
            'role' => $role,
            'status' => MembershipStatus::Approved,
            'enrollment_source' => 'officer_added',
            'recorded_email' => $user->email,
            'first_name' => 'Sample',
            'middle_name' => 'A.',
            'last_name' => 'User',
            'full_name_last_first_mi' => $name,
            'course' => 'BSIT',
            'year_level' => '4',
            'section' => 'A',
            'course_year_section' => 'BSIT 4A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2004-05-10',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.user',
            'active_fb_account' => $name,
            'cellphone_number' => '0917-000-0000',
            'approved_at' => now()->subDays(10),
        ]);

        app(ClubTenureService::class)->openTenure($membership, $user, $membership->approved_at);

        return [$user, $membership];
    }

    public function test_api_v1_health_endpoint_and_cors_headers_work_for_flutter_web(): void
    {
        $this->withHeaders([
            'Origin' => 'http://localhost:51738',
        ])
            ->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson([
                'ok' => true,
                'app' => 'CPF',
                'database' => 'connected',
            ])
            ->assertHeader('Access-Control-Allow-Origin', '*');

        $this->withHeaders([
            'Origin' => 'http://localhost:51738',
            'Access-Control-Request-Method' => 'POST',
            'Access-Control-Request-Headers' => 'Authorization, Content-Type',
        ])
            ->options('/api/v1/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', '*');
    }

    public function test_api_v1_login_me_and_logout_lifecycle(): void
    {
        [$officer] = $this->createVerifiedMember($this->alijis, CpfRole::President, 'Mercado, Sofia R.');

        PeerQuote::create([
            'campus_id' => $this->alijis->id,
            'submitted_by_user_id' => $officer->id,
            'quote_text' => 'Gentle steps still move you forward.',
            'author_name' => 'CPF Alijis',
            'mood_tag' => 'Calm',
            'is_approved' => true,
        ]);

        // Unauthenticated requests return 401 JSON
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Wrong password returns 422
        $this->postJson('/api/v1/auth/login', [
            'email' => $officer->email,
            'password' => 'WrongPassword!',
            'device_name' => 'Pixel 8',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('email');

        // Valid login issues Sanctum Bearer token + full profile payload
        $loginRes = $this->postJson('/api/v1/auth/login', [
            'email' => $officer->email,
            'password' => 'SecretPass2026!',
            'device_name' => 'Pixel 8',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.email', $officer->email)
            ->assertJsonPath('verified_role', 'president')
            ->assertJsonPath('campus.code', 'ALIJIS')
            ->assertJsonPath('permissions.can_scan_attendance', true)
            ->assertJsonPath('qr_unlocked', true)
            ->assertJsonPath('daily_quote.quote_text', 'Gentle steps still move you forward.');

        $token = (string) $loginRes->json('token');
        $this->assertNotEmpty($token);

        $raw = "CPF|campus:ALIJIS|user:{$officer->id}|role:president";
        $sig = substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, 16);
        $this->assertSame("{$raw}|sig:{$sig}", $loginRes->json('qr_payload'));

        // GET /api/v1/auth/me authenticates via Bearer token
        $this->withToken($token)
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('user.id', $officer->id)
            ->assertJsonPath('verified_role', 'president')
            ->assertJsonPath('qr_payload', "{$raw}|sig:{$sig}");

        // Logout revokes the current token
        $this->withToken($token)
            ->postJson('/api/v1/auth/logout')
            ->assertOk()
            ->assertJsonPath('status', 'logged_out');

        $this->assertDatabaseCount('personal_access_tokens', 0);

        $this->bearer($token)
            ->getJson('/api/v1/auth/me')
            ->assertUnauthorized();
    }

    private function bearer(string $token): static
    {
        app('auth')->forgetGuards();

        return $this->withToken($token);
    }

    public function test_api_v1_passkey_code_claims_atomically_and_returns_former_profile_for_alumni(): void
    {
        [$adviser] = $this->createVerifiedMember($this->alijis, CpfRole::Adviser, 'Montemayor, Elena V.');
        [$member, $membership] = $this->createVerifiedMember(
            $this->alijis,
            CpfRole::Member,
            'Reyes, Katrina M.',
            mustChangePassword: true,
        );

        $issued = app(IssueMemberPasskey::class)->handle($adviser, $membership, true, false);
        $plainCode = $issued['enrollment_code'];

        // Passkey login succeeds atomically and issues Sanctum Bearer token
        $passkeyRes = $this->postJson('/api/v1/auth/passkey-code', [
            'email' => $member->email,
            'passkey_code' => str_replace('-', '', $plainCode),
            'device_name' => 'Samsung A55',
        ])
            ->assertOk()
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonPath('user.id', $member->id)
            ->assertJsonPath('user.must_change_password', true)
            ->assertJsonPath('verified_role', 'member')
            ->assertJsonPath('qr_unlocked', false)
            ->assertJsonPath('qr_payload', null);

        // Replaying same passkey fails because it was atomically claimed
        $this->postJson('/api/v1/auth/passkey-code', [
            'email' => $member->email,
            'passkey_code' => $plainCode,
            'device_name' => 'Samsung A55',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('passkey_code');

        // Transition member to Former/Alumni -> /api/v1/auth/me exposes former role with qr_payload locked
        app(ClubTenureService::class)->transitionToAlumni($membership, $adviser, 'graduated');

        $this->bearer((string) $passkeyRes->json('token'))
            ->getJson('/api/v1/auth/me')
            ->assertOk()
            ->assertJsonPath('is_alumni', true)
            ->assertJsonPath('verified_role', null)
            ->assertJsonPath('former_role', 'member')
            ->assertJsonPath('former_role_label', 'Former Member')
            ->assertJsonPath('permissions.can_scan_attendance', false)
            ->assertJsonPath('qr_unlocked', false)
            ->assertJsonPath('qr_payload', null);
    }

    public function test_api_v1_events_listing_and_two_stage_qr_scan_reuses_same_rules(): void
    {
        [$saa] = $this->createVerifiedMember($this->alijis, CpfRole::SergeantAtArms, 'Mendoza, Rafael J.');
        [$member] = $this->createVerifiedMember($this->alijis, CpfRole::Member, 'Reyes, Katrina M.');

        $talisay = Campus::create([
            'code' => 'TALISAY',
            'name' => 'CHMSU Talisay Campus',
            'slug' => 'talisay',
            'current_academic_year' => '2026-2027',
            'is_active' => true,
        ]);
        [$otherOfficer] = $this->createVerifiedMember($talisay, CpfRole::President, 'Cruz, Angelo D.');

        $eventDate = now()->addDays(2)->toDateString();
        $event = CampusEvent::create([
            'campus_id' => $this->alijis->id,
            'title' => 'Mobile Sync General Assembly',
            'event_date' => $eventDate,
            'start_time' => '09:00',
            'end_time' => '11:00',
            'status' => 'approved',
            'created_by_user_id' => $saa->id,
            'approved_by_user_id' => $saa->id,
            'approved_at' => now(),
        ]);

        // Unauthenticated blocked
        $this->getJson('/api/v1/events')->assertUnauthorized();

        $saaToken = $saa->createToken('Officer Phone')->plainTextToken;
        $memberToken = $member->createToken('Member Phone')->plainTextToken;
        $otherOfficerToken = $otherOfficer->createToken('Other Campus Phone')->plainTextToken;

        $this->travelTo(Carbon::parse($eventDate.' 09:30:00'));

        try {
            $raw = "CPF|campus:ALIJIS|user:{$member->id}|role:member";
            $sig = substr(hash_hmac('sha256', $raw, (string) config('app.key')), 0, 16);
            $signedQr = "{$raw}|sig:{$sig}";

            // Regular member cannot operate QR scanner (403)
            $this->bearer($memberToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'login',
                ])
                ->assertForbidden();

            // Other campus officer cannot scan this campus's event (403)
            $this->bearer($otherOfficerToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'login',
                ])
                ->assertForbidden();

            // Officer cannot Log-Out before Log-In (422)
            $this->bearer($saaToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'logout',
                ])
                ->assertStatus(422);

            // Officer scans member IN (201)
            $this->bearer($saaToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'login',
                ])
                ->assertCreated()
                ->assertJsonPath('status', 'logged_in');

            // Member sees event + logged_in status in GET /api/v1/events
            $this->bearer($memberToken)
                ->getJson('/api/v1/events')
                ->assertOk()
                ->assertJsonPath('can_scan_events', false)
                ->assertJsonPath('events.0.id', $event->id)
                ->assertJsonPath('events.0.effective_status', 'active')
                ->assertJsonPath('events.0.can_scan', false)
                ->assertJsonPath('events.0.my_attendance.status', 'logged_in');

            // Officer sees can_scan = true and scans member OUT (200)
            $this->bearer($saaToken)
                ->getJson('/api/v1/events')
                ->assertOk()
                ->assertJsonPath('can_scan_events', true)
                ->assertJsonPath('events.0.can_scan', true);

            $this->bearer($saaToken)
                ->postJson("/api/v1/events/{$event->id}/scan", [
                    'qr_payload' => $signedQr,
                    'scan_type' => 'logout',
                ])
                ->assertOk()
                ->assertJsonPath('status', 'logged_out');

            $attendance = CampusEventAttendance::where('campus_event_id', $event->id)
                ->where('user_id', $member->id)
                ->firstOrFail();

            $this->assertNotNull($attendance->logged_in_at);
            $this->assertNotNull($attendance->logged_out_at);

            $this->bearer($memberToken)
                ->getJson('/api/v1/events')
                ->assertOk()
                ->assertJsonPath('events.0.my_attendance.status', 'completed');
        } finally {
            $this->travelBack();
        }
    }

    public function test_download_android_serves_apk_when_present_and_returns_clean_404_when_missing(): void
    {
        $apkDir = storage_path('app/releases');
        $apkPath = $apkDir.DIRECTORY_SEPARATOR.'cpf-android-latest.apk';
        $hadExisting = is_file($apkPath);
        $backupContent = $hadExisting ? file_get_contents($apkPath) : null;

        try {
            if ($hadExisting) {
                @unlink($apkPath);
            }

            // Missing APK returns clean 404 coming-soon HTML & JSON responses
            $this->get('/download/android')
                ->assertNotFound()
                ->assertSee('CPF Mobile APK Coming Soon');

            $this->getJson('/download/android')
                ->assertNotFound()
                ->assertJsonPath('status', 'coming_soon')
                ->assertJsonPath('platform', 'android');

            // Present APK serves downloadable Android package MIME type
            @mkdir($apkDir, 0755, true);
            file_put_contents($apkPath, "PK\x03\x04dummy-apk-payload");

            $downloadRes = $this->get('/download/android')->assertOk();
            $this->assertSame(
                'application/vnd.android.package-archive',
                $downloadRes->headers->get('Content-Type'),
            );
        } finally {
            if ($hadExisting && $backupContent !== null) {
                file_put_contents($apkPath, $backupContent);
            } elseif (! $hadExisting && is_file($apkPath)) {
                @unlink($apkPath);
            }
        }
    }
}
