<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DisplayScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_sidebar_opens_the_display_screen_in_a_new_tab(): void
    {
        foreach (['/', '/patients/today', '/queue-number', '/consultation-queue', '/review-queue'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('شاشة العرض')
                ->assertSee('href="/waiting"', false)
                ->assertSee('target="_blank"', false);
        }
    }

    public function test_waiting_screen_renders_the_display_view(): void
    {
        $this->get('/waiting')
            ->assertOk()
            ->assertSee('شاشة العرض')
            ->assertSee('id="queue-number"', false)
            ->assertSee('id="service-label"', false)
            ->assertSee('بانتظار أول استدعاء')
            ->assertSee('css/waiting.css')
            ->assertSee('js/waiting.js');
    }

    public function test_display_screen_labels_the_consultation_and_review_stages(): void
    {
        $script = file_get_contents(public_path('js/waiting.js'));

        $this->assertStringContainsString("consultation: 'المقابلة'", $script);
        $this->assertStringContainsString("review: 'النتيجة'", $script);
        $this->assertStringNotContainsString('المقابلة الأول', $script);
    }

    public function test_display_response_has_no_personal_data_fields(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $payload = $this->getJson('/api/queue/display')->assertOk()->json();

        $this->assertSame(['visit_id', 'queue_number', 'stage', 'is_paused', 'call_token'], array_keys($payload));

        $personal = ['name', 'full_name', 'phone', 'mobile', 'email', 'address', 'national_id', 'age', 'notes'];
        $this->assertSame([], array_values(array_intersect(array_keys($payload), $personal)));
    }

    public function test_display_switches_stage_when_the_same_patient_is_called_in_review(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertJson(['queue_number' => '001', 'stage' => 'consultation']);

        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();
        $this->postJson('/api/queue/stages/review/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertJson(['visit_id' => 1, 'queue_number' => '001', 'stage' => 'review']);
    }

    public function test_display_shows_the_new_number_when_each_queue_calls(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->getJson('/api/queue/display')
            ->assertJson(['queue_number' => '001', 'stage' => 'consultation']);

        $this->postJson('/api/queue/visits/1/complete')->assertOk();
        $this->postJson('/api/queue/stages/consultation/call-next')->assertOk();
        $this->getJson('/api/queue/display')
            ->assertJson(['queue_number' => '002', 'stage' => 'consultation']);
    }

    public function test_display_is_idle_when_there_is_no_active_call(): void
    {
        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson([
                'visit_id' => null,
                'queue_number' => null,
                'stage' => null,
                'is_paused' => false,
            ]);
    }
}
