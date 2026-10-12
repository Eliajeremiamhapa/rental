<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class AdminSeeder extends Seeder
{
    /**
     * Create the first system administrator account.
     *
     * Credentials come from the environment and nothing is created unless
     * ADMIN_PHONE is set, so a well-known default login is never shipped.
     * Admins are never created through public registration.
     */
    public function run(): void
    {
        $phone = env('ADMIN_PHONE');

        if (! $phone) {
            $this->command?->warn('ADMIN_PHONE is not set; skipping admin account creation.');

            return;
        }

        $phone = preg_replace('/[\s\-()]+/', '', $phone);

        $existing = User::where('phone', $phone)->first();

        if ($existing) {
            if (! $existing->isAdmin()) {
                $existing->update([
                    'role' => 'admin',
                    'previous_role' => $existing->role,
                ]);
                $this->command?->info("Promoted existing account {$phone} to admin.");
            } else {
                $this->command?->info("Admin account for {$phone} already exists; nothing to do.");
            }

            return;
        }

        $plainPassword = env('ADMIN_PASSWORD') ?: Str::password(24);
        $generated = ! env('ADMIN_PASSWORD');

        User::create([
            'name' => env('ADMIN_NAME') ?: 'Administrator',
            'phone' => $phone,
            'email' => env('ADMIN_EMAIL') ?: null,
            'password' => Hash::make($plainPassword),
            'role' => 'admin',
            'is_active' => true,
            // The admin must replace the initial password on first login.
            'must_change_password' => true,
        ]);

        $this->command?->info("Created admin account for {$phone}.");

        if ($generated) {
            // The password is only ever shown here, once. Without it the account
            // would be unreachable, since the reset flow needs a login first.
            $this->command?->warn('No ADMIN_PASSWORD was set, so a random password was generated:');
            $this->command?->line("  {$plainPassword}");
            $this->command?->warn('Save it now and change it at first login. It is not recoverable.');
        } else {
            $this->command?->info('The initial password must be changed at first login.');
        }
    }
}
