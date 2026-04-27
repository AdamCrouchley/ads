<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Creates the admin user for the dashboard.
 *
 * Run via: php artisan db:seed --class=AdminUserSeeder
 *
 * The password is read from env (DASHBOARD_ADMIN_PASSWORD) or generated
 * randomly and printed to the console. In production, set the password
 * in env before seeding.
 */
class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'adam@dorimedia.co.nz';
        $name = 'Adam Crouchley';

        $password = env('DASHBOARD_ADMIN_PASSWORD');
        $generated = false;
        if (!$password) {
            $password = bin2hex(random_bytes(8));
            $generated = true;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ]
        );

        if ($generated) {
            $this->command->info('====================================================');
            $this->command->info(' Admin user created');
            $this->command->info('====================================================');
            $this->command->info(' Email:    ' . $email);
            $this->command->info(' Password: ' . $password);
            $this->command->info('====================================================');
            $this->command->warn(' SAVE THIS PASSWORD NOW. It will not be shown again.');
            $this->command->info('====================================================');
        } else {
            $this->command->info('Admin user upserted: ' . $email);
        }
    }
}
