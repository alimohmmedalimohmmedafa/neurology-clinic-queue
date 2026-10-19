<?php

namespace Tests\Feature;

use App\Models\DisplayState;
use App\Models\QueueControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaitingScreenTest extends TestCase
{
    use RefreshDatabase;

    public function test_display_endpoint_is_idle_before_any_call_and_writes_nothing(): void
    {
        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson([
                'visit_id' => null,
                'queue_number' => null,
                'stage' => null,
                'is_paused' => false,
            ]);

        $this->assertSame(0, DisplayState::count());
    }

    public function test_display_tracks_the_last_called_visit_across_stages(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson([
                'visit_id' => 1,
                'queue_number' => '001',
                'stage' => 'consultation',
                'is_paused' => false,
            ]);

        $this->postJson('/api/queue/visits/1/complete')->assertOk();
        $this->postJson('/api/queue/stages/consultation/call-next')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertJson(['queue_number' => '002', 'stage' => 'consultation']);

        $this->postJson('/api/queue/visits/2/move-to-review')->assertOk();
        $this->postJson('/api/queue/stages/review/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertJson(['visit_id' => 2, 'queue_number' => '002', 'stage' => 'review']);

        $this->assertSame(1, DisplayState::count());
    }

    public function test_display_reports_paused_flag_of_the_displayed_stage_only(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits', ['stage' => 'review'])->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertJson(['stage' => 'consultation', 'is_paused' => false]);

        // Pausing another stage must not affect the displayed stage.
        QueueControl::firstOrCreate(['stage' => 'review']);
        QueueControl::where('stage', 'review')->update(['is_paused' => true, 'updated_at' => now()]);

        $this->getJson('/api/queue/display')
            ->assertJson(['stage' => 'consultation', 'is_paused' => false]);

        QueueControl::where('stage', 'consultation')->update(['is_paused' => true, 'updated_at' => now()]);

        $this->getJson('/api/queue/display')
            ->assertJson(['stage' => 'consultation', 'is_paused' => true]);
    }

    public function test_waiting_page_renders_screen_states_and_assets(): void
    {
        $this->get('/waiting')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('الانتظار مؤقتًا')
            ->assertSee('بانتظار أول استدعاء')
            ->assertSee('الرجاء الدخول')
            ->assertSee('انقر في أي مكان لتفعيل التنبيه الصوتي')
            ->assertSee('css/waiting.css')
            ->assertSee('js/waiting.js');
    }

    public function test_waiting_page_offers_a_sound_test_button(): void
    {
        $this->get('/waiting')
            ->assertOk()
            ->assertSee('id="btn-test-audio"', false)
            ->assertSee('اختبار الصوت');
    }

    public function test_call_token_stays_same_on_polls_and_changes_on_a_new_call(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $first = $this->getJson('/api/queue/display')->assertOk()->json();
        $again = $this->getJson('/api/queue/display')->assertOk()->json();

        $this->assertNotNull($first['call_token']);
        $this->assertSame($first, $again, 'a plain poll must not change the call token');

        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits/1/complete')->assertOk();
        $this->postJson('/api/queue/stages/consultation/call-next')->assertOk();

        $next = $this->getJson('/api/queue/display')->assertOk()->json();

        $this->assertSame('002', $next['queue_number']);
        $this->assertNotSame($first['call_token'], $next['call_token'], 'a new call must change the token');
    }

    public function test_idle_display_reports_a_null_call_token(): void
    {
        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson(['call_token' => null]);
    }

    public function test_waiting_script_uses_the_clip_with_two_distinct_rates(): void
    {
        $script = file_get_contents(public_path('js/waiting.js'));

        $this->assertFileExists(public_path('sounds/call-notification.mp3'));
        $this->assertStringContainsString("'/sounds/call-notification.mp3'", $script);
        $this->assertStringContainsString('consultation: 1', $script);
        $this->assertStringContainsString('review: 0.82', $script);
    }

    public function test_alert_audio_is_loaded_only_on_the_waiting_screen(): void
    {
        $this->get('/waiting')->assertOk()->assertSee('js/waiting.js');

        foreach (['/', '/consultation-queue', '/review-queue', '/patients/today'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertDontSee('js/waiting.js')
                ->assertDontSee('btn-test-audio', false);
        }
    }

    public function test_ticket_page_shows_clinic_name_number_date_and_service(): void
    {
        $visit = $this->postJson('/api/queue/visits')->assertCreated()->json();

        $this->get('/tickets/visits/'.$visit['id'])
            ->assertOk()
            ->assertSee(config('app.name'))
            ->assertSee('001')
            ->assertSee('المقابلة الأول')
            ->assertSee('التاريخ:')
            ->assertSee('الوقت:')
            ->assertSee('print-btn');
    }

    public function test_ticket_for_moved_visit_shows_original_number_with_review_service(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $this->get('/tickets/visits/1')
            ->assertOk()
            ->assertSee('001')
            ->assertSee('مراجعة الطبيب بعد النتيجة')
            ->assertDontSee('المقابلة الأول');
    }

    public function test_ticket_auto_print_only_when_requested(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->get('/tickets/visits/1?auto=1')
            ->assertOk()
            ->assertSee('window.addEventListener', false);

        $this->get('/tickets/visits/1')
            ->assertOk()
            ->assertDontSee('window.addEventListener', false);
    }

    public function test_missing_ticket_visit_returns_404(): void
    {
        $this->get('/tickets/visits/999')->assertNotFound();
    }
}
