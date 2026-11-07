<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => 'admin@example.com'],
            [
                'name'     => 'Administrador',
                'email'    => 'admin@example.com',
                'password' => Hash::make('Admin1234!'),
                'role'     => 'admin',
                'active'   => true,
            ]
        );

        $this->command->info('Admin creado: admin@example.com / Admin1234!');
    }
}
