<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\DigitalCertificate;
use App\Models\MemberAchievement;
use App\Models\MemberAward;
use App\Models\User;
use App\Services\Blockchain\BlockchainCredentialService;
use Illuminate\Database\Seeder;

class BlockchainCredentialsSeeder extends Seeder
{
    public function run(): void
    {
        $blockchain = app(BlockchainCredentialService::class);

        $campus = Campus::query()->where('code', 'ALIJIS')->where('is_active', true)->first()
            ?? Campus::query()->where('is_active', true)->first()
            ?? Campus::query()->first();

        if (! $campus) {
            return;
        }

        $adviser = User::query()->where('email', 'adviser@cpf.chmsu.edu.ph')->first()
            ?? User::query()->first();

        if (! $adviser) {
            return;
        }

        $users = [
            'adviser' => User::query()->where('email', 'adviser@cpf.chmsu.edu.ph')->first(),
            'president' => User::query()->where('email', 'president@cpf.chmsu.edu.ph')->first(),
            'secretary' => User::query()->where('email', 'secretary@cpf.chmsu.edu.ph')->first(),
            'treasurer' => User::query()->where('email', 'treasurer@cpf.chmsu.edu.ph')->first(),
            'member' => User::query()->where('email', 'katrina@cpf.chmsu.edu.ph')->first(),
            'applicant' => User::query()->where('email', 'paulfelippegtibooc@gmail.com')->first(),
        ];

        $sampleAchievements = [
            [
                'key' => 'member',
                'code' => 'CPF-ACH-2026-KTRN0001',
                'title' => 'Certified Peer Support Listener (Level 1)',
                'category' => 'Peer Facilitation',
                'achieved_on' => '2026-09-18',
                'description' => 'Completed 24 hours of active listening, empathy workshops, and supervised Drop-In Lounge peer sessions at CHMSU Talisay.',
            ],
            [
                'key' => 'member',
                'code' => 'CPF-ACH-2026-KTRN0002',
                'title' => 'Midterm Wellness Lounge Volunteer',
                'category' => 'Campus Wellness',
                'achieved_on' => '2026-10-22',
                'description' => 'Facilitated quiet study check-ins and peer encouragement activities for BSIT and BSIS students during midterm week.',
            ],
            [
                'key' => 'president',
                'code' => 'CPF-ACH-2026-PRES0001',
                'title' => 'Multi-Campus Peer Summit Lead Organizer',
                'category' => 'Student Leadership',
                'achieved_on' => '2026-10-05',
                'description' => 'Coordinated the annual CPF orientation uniting Talisay, Fortune Towne, Alijis, and Binalbagan chapters.',
            ],
            [
                'key' => 'secretary',
                'code' => 'CPF-ACH-2026-SECR0001',
                'title' => 'A–Z Roster & Onboarding Excellence',
                'category' => 'Chapter Operations',
                'achieved_on' => '2026-09-29',
                'description' => 'Maintained 100% verified member documentation and timely Messenger GC onboarding for incoming peer facilitators.',
            ],
            [
                'key' => 'treasurer',
                'code' => 'CPF-ACH-2026-TRES0001',
                'title' => 'Transparent Chapter Stewardship',
                'category' => 'Financial Governance',
                'achieved_on' => '2026-10-14',
                'description' => 'Delivered audited semester fund reports with zero discrepancies under Club Adviser oversight.',
            ],
            [
                'key' => 'adviser',
                'code' => 'CPF-ACH-2026-ADVS0001',
                'title' => 'CPF Chapter Mentorship & Guidance Oversight',
                'category' => 'Guidance Leadership',
                'achieved_on' => '2026-08-25',
                'description' => 'Led annual peer facilitator training, mental health triage protocols, and multi-campus chapter governance.',
            ],
            [
                'key' => 'applicant',
                'code' => 'CPF-ACH-2026-PAUL0001',
                'title' => 'Peer Orientation Attendee',
                'category' => 'Onboarding',
                'achieved_on' => '2026-11-10',
                'description' => 'Completed the introductory Circle of Peer Facilitators orientation and student privacy briefing.',
            ],
        ];

        foreach ($sampleAchievements as $item) {
            $recipient = $users[$item['key']] ?? null;
            if (! $recipient || MemberAchievement::query()->where('achievement_code', $item['code'])->exists()) {
                continue;
            }

            $achievement = MemberAchievement::create([
                'campus_id' => $campus->id,
                'user_id' => $recipient->id,
                'achievement_code' => $item['code'],
                'title' => $item['title'],
                'category' => $item['category'],
                'achieved_on' => $item['achieved_on'],
                'description' => $item['description'],
                'verified_by_user_id' => $adviser->id,
            ]);

            $blockchain->anchorCredential(
                $achievement,
                'achievement',
                $item['code'],
                [
                    'title' => $achievement->title,
                    'category' => $achievement->category,
                    'achieved_on' => $item['achieved_on'],
                    'description' => $achievement->description,
                ],
                $adviser,
            );
        }

        $sampleAwards = [
            [
                'key' => 'member',
                'code' => 'CPF-AWD-2026-KTRN0001',
                'title' => 'Beacon of Empathy Award',
                'award_category' => 'Peer Service',
                'academic_year' => '2026-2027',
                'awarded_on' => '2026-11-12',
                'citation' => 'Recognized for compassionate listening, steady presence in the Peer Lounge, and uplifting fellow CHMSU students during finals preparation.',
            ],
            [
                'key' => 'president',
                'code' => 'CPF-AWD-2026-PRES0001',
                'title' => 'Outstanding CPF Student Leader',
                'award_category' => 'Leadership',
                'academic_year' => '2026-2027',
                'awarded_on' => '2026-11-12',
                'citation' => 'Awarded for exemplary student leadership and fostering psychological safety across all CHMSU CPF chapters.',
            ],
            [
                'key' => 'secretary',
                'code' => 'CPF-AWD-2026-SECR0001',
                'title' => 'Distinguished Chapter Secretariat Award',
                'award_category' => 'Distinction',
                'academic_year' => '2026-2027',
                'awarded_on' => '2026-11-12',
                'citation' => 'For outstanding dedication to accurate membership records, confidentiality compliance, and peer communication.',
            ],
            [
                'key' => 'treasurer',
                'code' => 'CPF-AWD-2026-TRES0001',
                'title' => 'Integrity in Stewardship Award',
                'award_category' => 'Distinction',
                'academic_year' => '2026-2027',
                'awarded_on' => '2026-11-12',
                'citation' => 'For transparent, accountable financial management supporting student wellness events.',
            ],
            [
                'key' => 'adviser',
                'code' => 'CPF-AWD-2026-ADVS0001',
                'title' => 'Pillars of Guidance & Mentorship Award',
                'award_category' => 'Mentorship',
                'academic_year' => '2026-2027',
                'awarded_on' => '2026-11-12',
                'citation' => 'Honoring steadfast guidance, counseling supervision, and advocacy for student mental wellness.',
            ],
            [
                'key' => 'applicant',
                'code' => 'CPF-AWD-2026-PAUL0001',
                'title' => 'Rising Peer Advocate Recognition',
                'award_category' => 'Peer Service',
                'academic_year' => '2026-2027',
                'awarded_on' => '2026-11-14',
                'citation' => 'Recognized for proactive participation and strong commitment to peer facilitation values during onboarding.',
            ],
        ];

        foreach ($sampleAwards as $item) {
            $recipient = $users[$item['key']] ?? null;
            if (! $recipient || MemberAward::query()->where('award_code', $item['code'])->exists()) {
                continue;
            }

            $award = MemberAward::create([
                'campus_id' => $campus->id,
                'user_id' => $recipient->id,
                'award_code' => $item['code'],
                'title' => $item['title'],
                'award_category' => $item['award_category'],
                'academic_year' => $item['academic_year'],
                'awarded_on' => $item['awarded_on'],
                'citation' => $item['citation'],
                'issued_by_user_id' => $adviser->id,
            ]);

            $blockchain->anchorCredential(
                $award,
                'award',
                $item['code'],
                [
                    'title' => $award->title,
                    'award_category' => $award->award_category,
                    'academic_year' => $award->academic_year,
                    'awarded_on' => $item['awarded_on'],
                    'citation' => $award->citation,
                ],
                $adviser,
            );
        }

        $sampleCertificates = [
            [
                'key' => 'member',
                'code' => 'CPF-CERT-2026-KTRN0001',
                'title' => 'Certificate of Completion — Active Listening & Peer Support',
                'event_name' => 'CHMSU CPF Mental Wellness Training Seminar 2026',
                'issued_on' => '2026-09-20',
            ],
            [
                'key' => 'president',
                'code' => 'CPF-CERT-2026-PRES0001',
                'title' => 'Certificate of Appreciation — Student Keynote Speaker',
                'event_name' => 'CHMSU Multi-Campus Peer Facilitation Summit 2026',
                'issued_on' => '2026-10-06',
            ],
            [
                'key' => 'secretary',
                'code' => 'CPF-CERT-2026-SECR0001',
                'title' => 'Certificate of Recognition — Chapter Data Privacy Steward',
                'event_name' => 'RA 10173 Student Guidance Confidentiality Workshop',
                'issued_on' => '2026-10-10',
            ],
            [
                'key' => 'adviser',
                'code' => 'CPF-CERT-2026-ADVS0001',
                'title' => 'Certificate of Commendation — Lead Club Adviser',
                'event_name' => 'CHMSU Student Affairs & Guidance Recognition Day 2026',
                'issued_on' => '2026-11-05',
            ],
        ];

        foreach ($sampleCertificates as $item) {
            $recipient = $users[$item['key']] ?? null;
            if (! $recipient || DigitalCertificate::query()->where('certificate_code', $item['code'])->exists()) {
                continue;
            }

            $certificate = DigitalCertificate::create([
                'campus_id' => $campus->id,
                'user_id' => $recipient->id,
                'certificate_code' => $item['code'],
                'title' => $item['title'],
                'event_name' => $item['event_name'],
                'issued_on' => $item['issued_on'],
                'issued_by_user_id' => $adviser->id,
            ]);

            $blockchain->anchorCredential(
                $certificate,
                'certificate',
                $item['code'],
                [
                    'title' => $certificate->title,
                    'event_name' => $certificate->event_name,
                    'issued_on' => $item['issued_on'],
                ],
                $adviser,
            );
        }
    }
}
