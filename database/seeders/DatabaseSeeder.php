<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // No demo/sample data is seeded on purpose: production deploys must not
        // gain synthetic houses, rooms or tenants. Only the first owner and
        // admin accounts are created, and only when their env vars are set.
        $this->call([
            OwnerSeeder::class,
            AdminSeeder::class,
        ]);
    }
}
