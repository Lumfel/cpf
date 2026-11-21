<?php

namespace Database\Seeders;

use App\Enums\CpfRole;
use App\Enums\MembershipStatus;
use App\Models\Campus;
use App\Models\CampusCounselor;
use App\Models\CampusMembership;
use App\Models\CampusRoom;
use App\Models\OfficerGcChannel;
use App\Models\PasskeyIssuance;
use App\Models\PaymentDestination;
use App\Models\PeerQuote;
use App\Models\RoomRequest;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the CPF Multi-Campus Role-Based Database.
     *
     * 6-Role Hierarchy (Zero Admin Role):
     * - Adviser ('adviser'): Highest authority in charge; approves members, assigns officer roles, approves payment channels, archives GC.
     * - President ('president'): Approves/adds members, issues passkeys, confirms A-Z Messenger GC.
     * - Vice President ('vice_president'): Approves/adds members, issues passkeys, confirms A-Z Messenger GC.
     * - Secretary ('secretary'): Reviews applications, issues passkeys, confirms A-Z Messenger GC.
     * - Treasurer ('treasurer'): Validates payment submissions; cannot add/approve members.
     * - Member ('member'): Verified peer facilitator.
     */
    public function run(): void
    {
        $talisay = Campus::updateOrCreate(
            ['code' => 'TALISAY'],
            [
                'name' => 'CHMSU Talisay (Main Campus)',
                'slug' => 'talisay',
                'adviser_name' => 'Dr. Elena V. Montemayor, RGC',
                'gc_group_name' => 'CPF Talisay Official Peer GC (Coming Soon)',
                'is_active' => false,
            ],
        );

        $fortuneTowne = Campus::updateOrCreate(
            ['code' => 'FORTUNE_TOWNE'],
            [
                'name' => 'CHMSU Fortune Towne Campus',
                'slug' => 'fortune-towne',
                'adviser_name' => 'Prof. Marco L. Villanueva, RGC',
                'gc_group_name' => 'CPF Fortune Towne Official Peer GC (Coming Soon)',
                'is_active' => false,
            ],
        );

        $alijis = Campus::updateOrCreate(
            ['code' => 'ALIJIS'],
            [
                'name' => 'CHMSU Alijis Campus',
                'slug' => 'alijis',
                'adviser_name' => 'Dr. Elena V. Montemayor, RGC',
                'gc_group_name' => 'CPF Alijis Official Peer GC (AY 2026–2027)',
                'is_active' => true,
            ],
        );

        $binalbagan = Campus::updateOrCreate(
            ['code' => 'BINALBAGAN'],
            [
                'name' => 'CHMSU Binalbagan Campus',
                'slug' => 'binalbagan',
                'adviser_name' => 'Prof. Nathaniel P. Mendoza, RGC',
                'gc_group_name' => 'CPF Binalbagan Official Peer GC (Coming Soon)',
                'is_active' => false,
            ],
        );

        foreach ([$talisay, $fortuneTowne, $alijis, $binalbagan] as $campus) {
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
        }

        $defaultPassword = Hash::make('password');

        // 1. Club Adviser (In Charge - replaces Admin)
        $adviser = User::updateOrCreate(
            ['email' => 'adviser@cpf.chmsu.edu.ph'],
            [
                'name' => 'Montemayor, Elena V.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $adviser->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::Adviser,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $adviser->email,
                'first_name' => 'Elena', 'middle_name' => 'V.', 'last_name' => 'Montemayor',
                'full_name_last_first_mi' => 'Montemayor, Elena V.',
                'course' => 'Guidance Services',
                'year_level' => 'Faculty',
                'section' => 'Adviser',
                'course_year_section' => 'Guidance Services / Adviser',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '1984-05-14',
                'active_email' => $adviser->email,
                'active_fb_account' => 'Elena Montemayor RGC (Blue blazer at CHMSU Guidance Office)',
                'facebook_profile_url' => 'https://www.facebook.com/elena.montemayor.chmsu',
                'cellphone_number' => '0917-555-0101',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $adviser->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $adviser->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 2. President (Can add/approve members + confirm Messenger GC)
        $president = User::updateOrCreate(
            ['email' => 'president@cpf.chmsu.edu.ph'],
            [
                'name' => 'Mercado, Sofia R.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $president->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::President,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $president->email,
                'first_name' => 'Sofia', 'middle_name' => 'R.', 'last_name' => 'Mercado',
                'full_name_last_first_mi' => 'Mercado, Sofia R.',
                'course' => 'BS Psychology',
                'year_level' => '4',
                'section' => 'A',
                'course_year_section' => 'BS Psychology 4A',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '2004-09-21',
                'active_email' => $president->email,
                'active_fb_account' => 'Sofia Mercado (Wearing CPF cream lanyard smiling)',
                'facebook_profile_url' => 'https://www.facebook.com/sofia.mercado.cpf',
                'cellphone_number' => '0917-555-0102',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $adviser->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $adviser->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 2b. Vice President (Can approve/add members, issue passkeys, confirm Messenger GC)
        $vicePresident = User::updateOrCreate(
            ['email' => 'vice_president@cpf.chmsu.edu.ph'],
            [
                'name' => 'Bautista, Miguel A.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $vicePresident->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::VicePresident,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $vicePresident->email,
                'first_name' => 'Miguel', 'middle_name' => 'A.', 'last_name' => 'Bautista',
                'full_name_last_first_mi' => 'Bautista, Miguel A.',
                'course' => 'BSIT',
                'year_level' => '4',
                'section' => 'B',
                'course_year_section' => 'BSIT 4B',
                'sex' => 'Male',
                'gender_identity' => 'Man',
                'birthdate' => '2004-08-19',
                'active_email' => $vicePresident->email,
                'active_fb_account' => 'Miguel Bautista (Green CHMSU polo at library)',
                'facebook_profile_url' => 'https://www.facebook.com/miguel.bautista.cpf',
                'cellphone_number' => '0917-555-0106',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $adviser->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $adviser->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 3. Secretary (Issues passkeys & confirms Messenger GC; reviews online forms)
        $secretary = User::updateOrCreate(
            ['email' => 'secretary@cpf.chmsu.edu.ph'],
            [
                'name' => 'Alvarez, Hannah G.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $secretary->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::Secretary,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $secretary->email,
                'first_name' => 'Hannah', 'middle_name' => 'G.', 'last_name' => 'Alvarez',
                'full_name_last_first_mi' => 'Alvarez, Hannah G.',
                'course' => 'BSIS',
                'year_level' => '3',
                'section' => 'A',
                'course_year_section' => 'BSIS 3A',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '2005-02-11',
                'active_email' => $secretary->email,
                'active_fb_account' => 'Hannah Alvarez (White Filipiniana portrait)',
                'facebook_profile_url' => 'https://www.facebook.com/hannah.alvarez.cpf',
                'cellphone_number' => '0917-555-0103',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $president->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $adviser->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 4. Treasurer (Finance officer; CANNOT add/approve members)
        $treasurer = User::updateOrCreate(
            ['email' => 'treasurer@cpf.chmsu.edu.ph'],
            [
                'name' => 'Delgado, Liam C.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $treasurer->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::Treasurer,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $treasurer->email,
                'first_name' => 'Liam', 'middle_name' => 'C.', 'last_name' => 'Delgado',
                'full_name_last_first_mi' => 'Delgado, Liam C.',
                'course' => 'BSIT',
                'year_level' => '3',
                'section' => 'B',
                'course_year_section' => 'BSIT 3B',
                'sex' => 'Male',
                'gender_identity' => 'Man',
                'birthdate' => '2005-07-08',
                'active_email' => $treasurer->email,
                'active_fb_account' => 'Liam Delgado (Holding acoustic guitar outdoors)',
                'facebook_profile_url' => 'https://www.facebook.com/liam.delgado.cpf',
                'cellphone_number' => '0917-555-0104',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $president->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $adviser->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 4b. Auditor (Financial audit & report certification)
        $auditor = User::updateOrCreate(
            ['email' => 'auditor@cpf.chmsu.edu.ph'],
            [
                'name' => 'Castillo, Marco D.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $auditor->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::Auditor,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $auditor->email,
                'first_name' => 'Marco', 'middle_name' => 'D.', 'last_name' => 'Castillo',
                'full_name_last_first_mi' => 'Castillo, Marco D.',
                'course' => 'BSA',
                'year_level' => '4',
                'section' => 'A',
                'course_year_section' => 'BSA 4A',
                'sex' => 'Male',
                'gender_identity' => 'Man',
                'birthdate' => '2004-04-12',
                'active_email' => $auditor->email,
                'active_fb_account' => 'Marco Castillo (Auditor notebook portrait)',
                'facebook_profile_url' => 'https://www.facebook.com/marco.castillo.cpf',
                'cellphone_number' => '0917-555-0107',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $president->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $treasurer->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 4c. Business Manager (Organizational projects & business transactions)
        $businessManager = User::updateOrCreate(
            ['email' => 'business@cpf.chmsu.edu.ph'],
            [
                'name' => 'Soriano, Patricia E.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $businessManager->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::BusinessManager,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $businessManager->email,
                'first_name' => 'Patricia', 'middle_name' => 'E.', 'last_name' => 'Soriano',
                'full_name_last_first_mi' => 'Soriano, Patricia E.',
                'course' => 'BSBA',
                'year_level' => '3',
                'section' => 'A',
                'course_year_section' => 'BSBA 3A',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '2005-09-02',
                'active_email' => $businessManager->email,
                'active_fb_account' => 'Patricia Soriano (CPF event booth)',
                'facebook_profile_url' => 'https://www.facebook.com/patricia.soriano.cpf',
                'cellphone_number' => '0917-555-0108',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $president->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $treasurer->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 4d. Public Information Officer / PIO (Announcements, notices, GC outreach)
        $pio = User::updateOrCreate(
            ['email' => 'pio@cpf.chmsu.edu.ph'],
            [
                'name' => 'Navarro, Bianca F.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $pio->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::Pio,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $pio->email,
                'first_name' => 'Bianca', 'middle_name' => 'F.', 'last_name' => 'Navarro',
                'full_name_last_first_mi' => 'Navarro, Bianca F.',
                'course' => 'BS Psychology',
                'year_level' => '2',
                'section' => 'B',
                'course_year_section' => 'BS Psychology 2B',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '2006-01-24',
                'active_email' => $pio->email,
                'active_fb_account' => 'Bianca Navarro (Campus radio booth)',
                'facebook_profile_url' => 'https://www.facebook.com/bianca.navarro.cpf',
                'cellphone_number' => '0917-555-0109',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $president->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $treasurer->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 4e. Sergeant-at-Arms (Peace, calm & order during meetings & activities)
        $saa = User::updateOrCreate(
            ['email' => 'saa@cpf.chmsu.edu.ph'],
            [
                'name' => 'Mendoza, Rafael J.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $saa->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::SergeantAtArms,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'officer_added',
                'recorded_email' => $saa->email,
                'first_name' => 'Rafael', 'middle_name' => 'J.', 'last_name' => 'Mendoza',
                'full_name_last_first_mi' => 'Mendoza, Rafael J.',
                'course' => 'BSED',
                'year_level' => '3',
                'section' => 'A',
                'course_year_section' => 'BSED 3A',
                'sex' => 'Male',
                'gender_identity' => 'Man',
                'birthdate' => '2005-11-17',
                'active_email' => $saa->email,
                'active_fb_account' => 'Rafael Mendoza (CHMSU PE uniform)',
                'facebook_profile_url' => 'https://www.facebook.com/rafael.mendoza.cpf',
                'cellphone_number' => '0917-555-0110',
                'added_or_approved_by' => $adviser->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => true,
                'gc_confirmed_by' => $president->id,
                'gc_confirmed_at' => now(),
                'has_paid_dues' => true,
                'dues_confirmed_by' => $treasurer->id,
                'dues_confirmed_at' => now(),
            ],
        );

        // 5. Verified Member (Awaiting Messenger GC Checklist & Dues confirmation)
        $member = User::updateOrCreate(
            ['email' => 'katrina@cpf.chmsu.edu.ph'],
            [
                'name' => 'Reyes, Katrina M.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $member->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::Member,
                'status' => MembershipStatus::Approved,
                'enrollment_source' => 'online_form',
                'recorded_email' => $member->email,
                'first_name' => 'Katrina', 'middle_name' => 'M.', 'last_name' => 'Reyes',
                'full_name_last_first_mi' => 'Reyes, Katrina M.',
                'course' => 'BSIT',
                'year_level' => '4',
                'section' => 'A',
                'course_year_section' => 'BSIT 4A',
                'sex' => 'Female',
                'gender_identity' => 'Woman',
                'birthdate' => '2004-11-03',
                'active_email' => $member->email,
                'active_fb_account' => 'Katrina Reyes (Yellow cardigan in library)',
                'facebook_profile_url' => 'https://www.facebook.com/katrina.reyes.cpf',
                'cellphone_number' => '0917-555-0105',
                'added_or_approved_by' => $secretary->id,
                'approved_at' => now(),
                'is_in_messenger_gc' => false,
                'gc_confirmed_by' => null,
                'gc_confirmed_at' => null,
                'has_paid_dues' => false,
                'dues_confirmed_by' => null,
                'dues_confirmed_at' => null,
            ],
        );

        // 6. Pending Online Applicant (Submitted Google-Form-style Online Application)
        $applicant = User::updateOrCreate(
            ['email' => 'paulfelippegtibooc@gmail.com'],
            [
                'name' => 'Tibo-oc, Paul Felippe G.',
                'password' => $defaultPassword,
                'email_verified_at' => now(),
                'must_change_password' => false,
                'temporary_password_issued_at' => null,
            ],
        );

        CampusMembership::updateOrCreate(
            ['user_id' => $applicant->id, 'campus_id' => $alijis->id],
            [
                'role' => CpfRole::Member,
                'status' => MembershipStatus::Pending,
                'enrollment_source' => 'online_form',
                'recorded_email' => 'paulfelippegtibooc@gmail.com',
                'first_name' => 'Paul Felippe', 'middle_name' => 'G.', 'last_name' => 'Tibo-oc',
                'full_name_last_first_mi' => 'Tibo-oc, Paul Felippe G.',
                'course' => 'BSIT',
                'year_level' => '4',
                'section' => 'A',
                'course_year_section' => 'BSIT 4A',
                'sex' => 'Male',
                'gender_identity' => 'Man',
                'birthdate' => '2004-06-15',
                'active_email' => 'paulfelippegtibooc@gmail.com',
                'active_fb_account' => 'Paul Felippe Tibo-oc (CHMSU BSIT uniform portrait)',
                'facebook_profile_url' => 'https://www.facebook.com/paulfelippe.tibooc',
                'cellphone_number' => '0917-555-0199',
                'has_paid_dues' => false,
                'dues_confirmed_by' => null,
                'dues_confirmed_at' => null,
            ],
        );

        // Clean up any prior Talisay/inactive-campus seeded memberships for the 11 sample accounts
        // now that the portal is locked to CHMSU Alijis Campus.
        $sampleUserIds = [
            $adviser->id,
            $president->id,
            $vicePresident->id,
            $secretary->id,
            $treasurer->id,
            $auditor->id,
            $businessManager->id,
            $pio->id,
            $saa->id,
            $member->id,
            $applicant->id,
        ];

        CampusMembership::query()
            ->whereIn('user_id', $sampleUserIds)
            ->where('campus_id', '!=', $alijis->id)
            ->delete();

        PasskeyIssuance::query()
            ->where('target_user_id', $applicant->id)
            ->delete();

        // 7. Active Officers Private GC Channel (FR-07 — CHMSU Alijis Main Officers GC)
        OfficerGcChannel::updateOrCreate(
            [
                'campus_id' => $alijis->id,
                'academic_year' => '2026-2027',
            ],
            [
                'channel_key' => 'campus.alijis.officers-gc.2026-2027',
                'is_active' => true,
            ],
        );

        \App\Models\CounselorGcMessage::firstOrCreate(
            [
                'campus_id' => $alijis->id,
                'sender_user_id' => $adviser->id,
                'message_body' => 'Welcome to the CHMSU Alijis Guidance Counselors Private Channel. Coordinate testing room bookings and student triage updates here.',
            ],
            [
                'sender_role' => CpfRole::Adviser->value,
            ],
        );

        // 8. Adviser-Approved Payment Destination (FR-08)
        PaymentDestination::updateOrCreate(
            [
                'campus_id' => $alijis->id,
                'channel' => 'GCash',
                'account_number' => '0917-555-8800',
            ],
            [
                'account_name' => 'CPF CHMSU Alijis Chapter',
                'is_active' => true,
                'approved_by_adviser_id' => $adviser->id,
            ],
        );

        // 9. Calming Peer Quotes (FR-04)
        $quotes = [
            [
                'quote_text' => 'You do not have to carry everything alone today. Even a small step forward is progress.',
                'author_name' => 'CPF Peer Facilitation Note',
                'mood_tag' => 'Calm',
            ],
            [
                'quote_text' => 'Listening with kindness can turn someone’s hardest week into a moment of relief.',
                'author_name' => 'Circle of Peer Facilitators',
                'mood_tag' => 'Empathy',
            ],
            [
                'quote_text' => 'Take a breath. Your presence in this community matters more than perfection.',
                'author_name' => 'CPF Guidance & Wellness',
                'mood_tag' => 'Grounding',
            ],
        ];

        foreach ($quotes as $q) {
            PeerQuote::firstOrCreate(
                ['quote_text' => $q['quote_text']],
                [
                    'campus_id' => $alijis->id,
                    'submitted_by_user_id' => $adviser->id,
                    'author_name' => $q['author_name'],
                    'mood_tag' => $q['mood_tag'],
                    'is_approved' => true,
                ],
            );
        }

        // 10. 3 Sample Guidance Counselors (Mon–Fri Vacant Time) & Anonymous Calendar Bookings
        $alijisCounselors = CampusCounselor::ensureDefaultCounselorsForCampus($alijis->id);
        $alijisTestingRoom = CampusRoom::query()
            ->where('campus_id', $alijis->id)
            ->where('is_single_allowed_room', true)
            ->first();

        if ($alijisTestingRoom && $alijisCounselors->count() >= 3) {
            $sampleBookings = [
                [
                    'counselor' => $alijisCounselors[0],
                    'date' => now()->next(CarbonInterface::MONDAY)->format('Y-m-d'),
                    'start_time' => '08:30:00',
                    'end_time' => '10:00:00',
                ],
                [
                    'counselor' => $alijisCounselors[1],
                    'date' => now()->next(CarbonInterface::WEDNESDAY)->format('Y-m-d'),
                    'start_time' => '09:00:00',
                    'end_time' => '10:30:00',
                ],
                [
                    'counselor' => $alijisCounselors[2],
                    'date' => now()->next(CarbonInterface::FRIDAY)->format('Y-m-d'),
                    'start_time' => '15:00:00',
                    'end_time' => '16:30:00',
                ],
            ];

            foreach ($sampleBookings as $booking) {
                RoomRequest::firstOrCreate(
                    [
                        'room_id' => $alijisTestingRoom->id,
                        'request_date' => $booking['date'],
                        'start_time' => $booking['start_time'],
                    ],
                    [
                        'campus_id' => $alijis->id,
                        'counselor_id' => $booking['counselor']->id,
                        'requested_by_user_id' => $adviser->id,
                        'purpose' => 'Confidential Counseling Intake',
                        'end_time' => $booking['end_time'],
                        'status' => 'approved',
                        'reviewed_by_user_id' => $adviser->id,
                    ],
                );
            }
        }

        $this->call(BlockchainCredentialsSeeder::class);

        // 11. Sample CHMSU Alijis Campus Events (Past Completed, Active Today, Future Pending Approval)
        $completedEvent = \App\Models\CampusEvent::updateOrCreate(
            [
                'campus_id' => $alijis->id,
                'title' => 'CPF Peer Listening & Psychological First Aid Workshop',
            ],
            [
                'description' => 'Foundational peer listening workshop for CHMSU Alijis facilitators. Members can write post-event reflections straight into their encrypted Time Capsule.',
                'location' => 'CHMSU Alijis Guidance Audio-Visual Room',
                'event_date' => now()->subDays(2)->format('Y-m-d'),
                'start_time' => '09:00',
                'end_time' => '11:30',
                'status' => 'approved',
                'created_by_user_id' => $president->id,
                'approved_by_user_id' => $adviser->id,
                'approved_at' => now()->subDays(5),
                'activated_at' => now()->subDays(2)->setTime(9, 0),
                'completed_at' => now()->subDays(2)->setTime(11, 30),
            ],
        );

        $activeEvent = \App\Models\CampusEvent::updateOrCreate(
            [
                'campus_id' => $alijis->id,
                'title' => 'CHMSU Alijis Peer Wellness Drop-In & General Assembly',
            ],
            [
                'description' => 'Live campus activity open all day today for testing Officer QR Scanner Log-In, Log-Out, and the live Member Checklist.',
                'location' => 'CHMSU Alijis Student Activity Center',
                'event_date' => now()->format('Y-m-d'),
                'start_time' => '00:00',
                'end_time' => '23:59',
                'status' => 'approved',
                'created_by_user_id' => $adviser->id,
                'approved_by_user_id' => $adviser->id,
                'approved_at' => now()->subDays(2),
                'activated_at' => now()->startOfDay(),
                'completed_at' => null,
            ],
        );

        \App\Models\CampusEvent::updateOrCreate(
            [
                'campus_id' => $alijis->id,
                'title' => 'Mental Health Awareness Week Kickoff & Booth Planning',
            ],
            [
                'description' => 'Upcoming chapter event proposed by the President. Ready for Club Adviser approval, drag-and-drop rescheduling, or cancellation.',
                'location' => 'CHMSU Alijis Covered Court',
                'event_date' => now()->addDays(3)->format('Y-m-d'),
                'start_time' => '13:00',
                'end_time' => '16:00',
                'status' => 'pending_approval',
                'created_by_user_id' => $president->id,
                'approved_by_user_id' => null,
                'approved_at' => null,
                'activated_at' => null,
                'completed_at' => null,
            ],
        );

        $alijisMemberIds = CampusMembership::query()
            ->where('campus_id', $alijis->id)
            ->where('status', MembershipStatus::Approved->value)
            ->pluck('user_id');

        foreach ($alijisMemberIds as $recipientId) {
            \App\Models\CampusEventNotification::firstOrCreate(
                [
                    'campus_event_id' => $activeEvent->id,
                    'user_id' => $recipientId,
                    'type' => 'event_activated',
                ],
                [
                    'campus_id' => $alijis->id,
                    'title' => "Activity Now Active: {$activeEvent->title}",
                    'message' => "{$activeEvent->title} is active today ({$activeEvent->start_time}–{$activeEvent->end_time}). Officers can scan Attendance QR codes for Log-In & Log-Out.",
                ],
            );
        }
    }
}
