<?php

namespace Tests\Feature\Settings;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusMembership;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_page_is_displayed()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->get(route('profile.edit'));

        $response->assertOk();
    }

    public function test_profile_information_can_be_updated()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'test@example.com',
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $user->refresh();

        $this->assertSame('Test User', $user->name);
        $this->assertSame('test@example.com', $user->email);
        $this->assertNull($user->email_verified_at);
    }

    public function test_email_is_stored_in_lowercase_so_the_user_can_still_log_in()
    {
        $user = User::factory()->create();

        $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'Test@Example.com',
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame('test@example.com', $user->refresh()->email);

        $this->post(route('logout'));

        $this->post(route('login.store'), [
            'email' => 'Test@Example.com',
            'password' => 'password',
        ]);

        $this->assertAuthenticatedAs($user);
    }

    public function test_email_must_be_unique_regardless_of_case()
    {
        User::factory()->create(['email' => 'taken@example.com']);

        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => 'Taken@Example.com',
            ]);

        $response
            ->assertSessionHasErrors('email')
            ->assertRedirect(route('profile.edit'));
    }

    public function test_email_verification_status_is_unchanged_when_the_email_address_is_unchanged()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->patch(route('profile.update'), [
                'name' => 'Test User',
                'email' => $user->email,
            ]);

        $response
            ->assertSessionHasNoErrors()
            ->assertRedirect(route('profile.edit'));

        $this->assertNotNull($user->refresh()->email_verified_at);
    }

    public function test_user_can_delete_their_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->delete(route('profile.destroy'), [
                'password' => 'password',
            ]);

        $response->assertForbidden();

        $this->assertAuthenticatedAs($user);
        $this->assertNotNull($user->fresh());
    }

    public function test_correct_password_must_be_provided_to_delete_account()
    {
        $user = User::factory()->create();

        $response = $this
            ->actingAs($user)
            ->from(route('profile.edit'))
            ->delete(route('profile.destroy'), [
                'password' => 'wrong-password',
            ]);

        $response->assertForbidden();

        $this->assertNotNull($user->fresh());
    }

    private function approveCampusMember(User $user, Campus $campus, CpfRole $role = CpfRole::Member): CampusMembership
    {
        return CampusMembership::create([
            'user_id' => $user->id,
            'campus_id' => $campus->id,
            'role' => $role,
            'status' => MembershipStatus::Approved,
            'enrollment_source' => 'officer_added',
            'recorded_email' => $user->email,
            'first_name' => 'Sample',
            'last_name' => 'Member',
            'full_name_last_first_mi' => $user->name,
            'course' => 'BSIT',
            'year_level' => '3',
            'section' => 'A',
            'course_year_section' => 'BSIT 3A',
            'sex' => 'Female',
            'gender_identity' => 'Woman',
            'birthdate' => '2005-01-01',
            'active_email' => $user->email,
            'facebook_profile_url' => 'https://www.facebook.com/sample.member',
            'active_fb_account' => $user->name,
            'cellphone_number' => '0917-000-0001',
        ]);
    }

    public function test_profile_photo_is_stored_on_private_local_disk_and_enforces_same_campus_access(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $alijis = Campus::create(['code' => 'ALIJIS', 'name' => 'CHMSU Alijis Campus', 'slug' => 'alijis']);
        $talisay = Campus::create(['code' => 'TALISAY', 'name' => 'CHMSU Talisay Campus', 'slug' => 'talisay']);

        $member = User::factory()->create(['must_change_password' => false]);
        $sameCampusPeer = User::factory()->create(['must_change_password' => false]);
        $outsider = User::factory()->create(['must_change_password' => false]);

        $this->approveCampusMember($member, $alijis);
        $this->approveCampusMember($sameCampusPeer, $alijis);
        $this->approveCampusMember($outsider, $talisay);

        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');

        $this->actingAs($member)
            ->from(route('profile.edit'))
            ->post(route('profile.photo.update'), [
                'photo' => UploadedFile::fake()->createWithContent('avatar1.png', $pngBytes),
            ])
            ->assertRedirect(route('profile.edit'));

        $firstPath = $member->fresh()->profile_photo_path;
        $this->assertNotNull($firstPath);
        Storage::disk('local')->assertExists($firstPath);
        Storage::disk('public')->assertMissing($firstPath);

        $this->actingAs($sameCampusPeer)
            ->get(route('profile-photos.show', $member))
            ->assertOk();

        $this->actingAs($outsider)
            ->get(route('profile-photos.show', $member))
            ->assertForbidden();

        // Replacing photo removes previous private file and stores the new one on 'local'
        $this->actingAs($member)
            ->from(route('profile.edit'))
            ->post(route('profile.photo.update'), [
                'photo' => UploadedFile::fake()->createWithContent('avatar2.png', $pngBytes),
            ])
            ->assertRedirect(route('profile.edit'));

        $secondPath = $member->fresh()->profile_photo_path;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('local')->assertMissing($firstPath);
        Storage::disk('local')->assertExists($secondPath);

        // Removing photo deletes private file and clears profile_photo_path
        $this->actingAs($member)
            ->delete(route('profile.photo.destroy'))
            ->assertRedirect();

        $this->assertNull($member->fresh()->profile_photo_path);
        Storage::disk('local')->assertMissing($secondPath);
    }

    public function test_legacy_profile_photo_on_public_disk_is_served_and_cleaned_up(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $campus = Campus::create(['code' => 'ALIJIS', 'name' => 'CHMSU Alijis Campus', 'slug' => 'alijis']);
        $member = User::factory()->create(['must_change_password' => false]);
        $outsiderCampus = Campus::create(['code' => 'TALISAY', 'name' => 'CHMSU Talisay Campus', 'slug' => 'talisay']);
        $outsider = User::factory()->create(['must_change_password' => false]);

        $this->approveCampusMember($member, $campus);
        $this->approveCampusMember($outsider, $outsiderCampus);

        $pngBytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==');
        $legacyPath = 'profile-photos/legacy-member.png';
        Storage::disk('public')->put($legacyPath, $pngBytes);
        $member->forceFill(['profile_photo_path' => $legacyPath])->save();

        $this->actingAs($outsider)
            ->get(route('profile-photos.show', $member))
            ->assertForbidden();

        // Public-disk fallback is removed: files not on local disk return 404, while destroyPhoto still cleans them up
        $this->actingAs($member)
            ->get(route('profile-photos.show', $member))
            ->assertNotFound();

        $this->actingAs($member)
            ->delete(route('profile.photo.destroy'))
            ->assertRedirect();

        $this->assertNull($member->fresh()->profile_photo_path);
        Storage::disk('public')->assertMissing($legacyPath);
    }
}
