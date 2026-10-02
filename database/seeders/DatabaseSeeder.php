<?php

namespace Database\Seeders;

use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        foreach (['super_admin', 'admin', 'user'] as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
            ]);
        }

        $user = User::firstOrCreate(
            ['email' => 'irfansyahahmad26@gmail.com'],
            ['name' => 'Irfansbom', 'password' => 'random']
        );
        $user->assignRole('user');

        $superAdmin = User::firstOrCreate(
            ['email' => 'mnursyahputra0@gmail.com'],
            ['name' => 'Muhammad_Nur_Syahputra', 'password' => 'random']
        );
        $superAdmin->assignRole('super_admin');
    }
}
