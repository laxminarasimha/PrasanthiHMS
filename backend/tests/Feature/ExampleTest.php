<?php

namespace Tests\Feature;

// use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    /**
     * A basic test example.
     */
    public function test_page_addresses_are_handed_to_the_web_app_and_api_misses_stay_json(): void
    {
        $spa = public_path('spa.html');
        $existed = is_file($spa);
        if (! $existed) {
            file_put_contents($spa, '<!doctype html><div id="root"></div>');
        }

        try {
            $this->get('/')->assertOk();
            $this->get('/front-desk')->assertOk();
            $this->getJson('/api/no-such-endpoint')->assertNotFound()->assertJsonStructure(['message']);
        } finally {
            if (! $existed) {
                unlink($spa);
            }
        }
    }
}
