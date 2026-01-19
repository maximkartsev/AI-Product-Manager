<?php

namespace Database\Seeders;

use App\Models\User;
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
        // User::factory(10)->create();

        $usersData = [
            [
            'name' => 'Test User',
            'email' => 'test@test.com',
            'password' =>  bcrypt('123456'),
        ],
            [
            'name' => 'Admin User',
            'email' => 'test@gmail.com',
            'password' => bcrypt('654321'),
            ]
        ];


        foreach ($usersData as $input) {
            $user = User::where('email', $input['email'])->first();
            if ($user) {
                continue;
            }
            User::factory()->create($input);
        }
    }
}
