<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\VerificationStatus;
use App\Models\ClinicianProfile;
use App\Models\PatientProfile;
use App\Models\Specialty;
use App\Models\User;
use Illuminate\Database\Seeder;

/** Local/dev only. Creates an admin, one verified clinician, one pending clinician and one patient. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            ['anxiety', 'اضطراب', 'Anxiety'], ['depression', 'افسردگی', 'Depression'], ['couples', 'زوج‌درمانی', 'Couples therapy'],
            ['child', 'کودک و نوجوان', 'Child & adolescent'], ['trauma', 'تروما', 'Trauma'], ['addiction', 'اعتیاد', 'Addiction'],
            ['sleep', 'خواب', 'Sleep'], ['ocd', 'وسواس', 'OCD'],
        ] as [$slug, $fa, $en]) {
            Specialty::updateOrCreate(['slug' => $slug], ['name_fa' => $fa, 'name_en' => $en]);
        }

        User::updateOrCreate(['email' => 'admin@ravan.local'], ['name' => 'Admin', 'password' => 'change-me-please', 'role' => Role::Admin->value]);

        $clin = User::updateOrCreate(['email' => 'dr.sara@ravan.local'], ['name' => 'دکتر سارا احمدی', 'password' => 'change-me-please', 'role' => Role::Clinician->value]);
        $profile = ClinicianProfile::updateOrCreate(['user_id' => $clin->id], [
            'title' => 'روان‌شناس بالینی', 'license_number' => 'PS-12345', 'license_authority' => 'سازمان نظام روان‌شناسی و مشاوره',
            'bio_fa' => 'روان‌شناس بالینی با ۱۲ سال سابقه در درمان اضطراب و افسردگی.', 'languages' => ['fa', 'en'],
            'years_experience' => 12, 'session_fee' => 6000000, 'currency' => 'IRR', 'session_length_min' => 50,
            'session_modes' => ['text', 'audio', 'video'], 'verification_status' => VerificationStatus::Approved, 'verified_at' => now(),
        ]);
        $profile->specialties()->sync(Specialty::whereIn('slug', ['anxiety', 'depression'])->pluck('id'));
        foreach ([0, 1, 2, 3] as $wd) {
            $profile->schedules()->updateOrCreate(['weekday' => $wd, 'start_time' => '09:00:00'], ['end_time' => '13:00:00', 'slot_minutes' => 60]);
        }

        $pending = User::updateOrCreate(['email' => 'dr.pending@ravan.local'], ['name' => 'دکتر رضا کریمی', 'password' => 'change-me-please', 'role' => Role::Clinician->value]);
        ClinicianProfile::updateOrCreate(['user_id' => $pending->id], ['title' => 'روان‌پزشک', 'license_number' => 'MD-999', 'license_authority' => 'سازمان نظام پزشکی', 'session_modes' => ['video']]);

        $patient = User::updateOrCreate(['email' => 'patient@ravan.local'], ['name' => 'کاربر نمونه', 'password' => 'change-me-please', 'role' => Role::Patient->value]);
        PatientProfile::updateOrCreate(['user_id' => $patient->id], ['preferred_language' => 'fa']);
    }
}
