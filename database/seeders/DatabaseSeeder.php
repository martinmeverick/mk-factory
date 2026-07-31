<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Bez WithoutModelEvents — doménová pravidla (auto-fill organization_id,
     * neměnnost faktur) závisejí na model events.
     */
    public function run(): void
    {
        $this->call(DemoSeeder::class);
    }
}
