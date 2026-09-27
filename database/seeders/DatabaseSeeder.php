<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Dummy data is deliberately gated on the environment. `db:seed` with no
     * arguments is easy to run against the wrong database, and this project's
     * seed data carries published passwords - it must never be one typo away
     * from a live shop's records.
     */
    public function run(): void
    {
        if (app()->environment('production')) {
            $this->command?->warn(
                'Skipped: no seed data is defined for production. '
                .'For a throwaway dataset run: php artisan db:seed --class=DummyDataSeeder'
            );

            return;
        }

        $this->call(DummyDataSeeder::class);
    }
}
