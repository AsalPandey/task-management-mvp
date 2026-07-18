<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use LogicException;

class UsersTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        throw new LogicException(
            'UsersTableSeeder is disabled because it previously created fixed-password demo users. Use DemoSeeder with its explicit local-only safeguards.'
        );
    }
}
