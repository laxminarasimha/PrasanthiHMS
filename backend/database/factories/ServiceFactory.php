<?php

namespace Database\Factories;

use App\Models\Service;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Service>
 */
class ServiceFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => 'Consultation', 'type' => 'appointment', 'price' => 200, 'gst_percent' => 0];
    }
}
