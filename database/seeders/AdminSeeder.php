<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'name'     => 'Admin',
            'username' => env('ADMIN_SEED_USERNAME', 'admin'),
            'email'    => env('ADMIN_SEED_EMAIL', 'admin@example.com'),
            'password' => bcrypt(env('ADMIN_SEED_PASSWORD', 'change-me-now')),
            'role'     => 'admin',
        ]);
    }
}