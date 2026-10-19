<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class StaffUserSeeder extends Seeder
{
    /**
     * Create the first staff login from environment values, so no password is
     * ever hard-coded. Set STAFF_USERNAME and STAFF_PASSWORD before seeding.
     */
    public function run(): void
    {
        $username = env('STAFF_USERNAME');
        $password = env('STAFF_PASSWORD');

        if (! $username || ! $password) {
            $this->command?->warn(
                'تم تخطي إنشاء حساب الموظف: حدّد STAFF_USERNAME و STAFF_PASSWORD في ملف .env ثم شغّل الأمر staff:create.'
            );

            return;
        }

        User::updateOrCreate(
            ['username' => $username],
            [
                'name' => env('STAFF_NAME', $username),
                'email' => $username.'@staff.local',
                'password' => Hash::make($password),
            ]
        );
    }
}
