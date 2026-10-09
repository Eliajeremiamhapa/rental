<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class OwnerSeeder extends Seeder
{
    /**
     * Create the very first owner account.
     *
     * The credentials come from the environment and nothing is created unless
     * OWNER_PHONE is set. This deliberately avoids shipping a well-known
     * default login (a fixed "password" on a published phone number), which
     * would otherwise be recreated by `php artisan db:seed` on every deploy.
     */
    public function run(): void
    {
        $phone = env('OWNER_PHONE');

        if (! $phone) {
            $this->command?->warn('OWNER_PHONE is not set; skipping owner account creation.');

            return;
        }

        $phone = preg_replace('/[\s\-()]+/', '', $phone);

        if (User::where('phone', $phone)->exists()) {
            $this->command?->info("Owner account for {$phone} already exists; nothing to do.");

            return;
        }

        $plainPassword = env('OWNER_PASSWORD') ?: Str::password(24);
        $generated = ! env('OWNER_PASSWORD');

        User::create([
            'name' => env('OWNER_NAME') ?: 'Owner',
            'phone' => $phone,
            'email' => env('OWNER_EMAIL') ?: null,
            'password' => Hash::make($plainPassword),
            'role' => 'owner',
            'is_active' => true,
            // The owner must replace the initial password on first login.
            'must_change_password' => true,
        ]);

        $this->command?->info("Created owner account for {$phone}.");

        if ($generated) {
            // The password is only ever shown here, once. Without it the account
            // would be unreachable, since the reset flow needs a login first.
            $this->command?->warn('No OWNER_PASSWORD was set, so a random password was generated:');
            $this->command?->line("  {$plainPassword}");
            $this->command?->warn('Save it now and change it at first login. It is not recoverable.');
        } else {
            $this->command?->info('The initial password must be changed at first login.');
        }
    }
}
