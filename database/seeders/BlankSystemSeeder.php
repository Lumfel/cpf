<?php

namespace Database\Seeders;

use App\Models\Campus;
use App\Models\CampusRoom;
use Illuminate\Database\Seeder;

class BlankSystemSeeder extends Seeder
{
    /**
     * Blank First-Boot Environment Seeder.
     *
     * Seeds only clean CHMSU campus shells and their single Testing Room, with zero users,
     * zero memberships, zero Adviser, and zero activity records so the First-Boot Adviser
     * Setup modal appears immediately when the app opens.
     */
    public function run(): void
    {
        $campuses = [
            [
                'code' => 'ALIJIS',
                'name' => 'CHMSU Alijis Campus',
                'slug' => 'alijis',
                'current_academic_year' => '2026-2027',
                'adviser_name' => null,
                'gc_group_name' => 'CPF Alijis Official Peer GC (AY 2026–2027)',
                'is_active' => true,
            ],
            [
                'code' => 'TALISAY',
                'name' => 'CHMSU Talisay (Main Campus)',
                'slug' => 'talisay',
                'current_academic_year' => '2026-2027',
                'adviser_name' => null,
                'gc_group_name' => 'CPF Talisay Official Peer GC (Coming Soon)',
                'is_active' => false,
            ],
            [
                'code' => 'FORTUNE_TOWNE',
                'name' => 'CHMSU Fortune Towne Campus',
                'slug' => 'fortune-towne',
                'current_academic_year' => '2026-2027',
                'adviser_name' => null,
                'gc_group_name' => 'CPF Fortune Towne Official Peer GC (Coming Soon)',
                'is_active' => false,
            ],
            [
                'code' => 'BINALBAGAN',
                'name' => 'CHMSU Binalbagan Campus',
                'slug' => 'binalbagan',
                'current_academic_year' => '2026-2027',
                'adviser_name' => null,
                'gc_group_name' => 'CPF Binalbagan Official Peer GC (Coming Soon)',
                'is_active' => false,
            ],
        ];

        foreach ($campuses as $data) {
            $campus = Campus::updateOrCreate(['code' => $data['code']], $data);

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
    }
}
