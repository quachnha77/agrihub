<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Dữ liệu demo chỉ dành cho máy dev; không bao giờ chạy ở staging/production.
        if (app()->environment('local')) {
            $this->call(DevSeeder::class);
        }
    }
}
