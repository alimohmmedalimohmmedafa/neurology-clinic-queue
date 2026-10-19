<?php

namespace Tests\Feature;

use App\Models\QueueEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IssueQueueNumberTest extends TestCase
{
    use RefreshDatabase;

    public function test_issue_page_shows_issue_section_without_creating_any_visit(): void
    {
        $this->get('/queue-number')
            ->assertOk()
            ->assertSee('إصدار رقم انتظار')
            ->assertSee('إصدار رقم جديد')
            ->assertSee('الاستقبال')
            ->assertSee('جميع مرضى اليوم')
            ->assertSee('js/csrf.js')
            ->assertSee('js/issue-ticket.js')
            ->assertDontSee('type="text"', false)
            ->assertDontSee('type="password"', false);

        $this->get('/queue-number')->assertOk();

        $this->assertDatabaseCount('visits', 0);
    }

    public function test_sidebar_section_exists_on_reception_and_today_pages(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('إصدار رقم انتظار')
            ->assertSee('/queue-number');

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('إصدار رقم انتظار')
            ->assertSee('/queue-number');
    }

    public function test_issuing_creates_todays_waiting_visit_without_any_call(): void
    {
        $this->postJson('/api/queue/visits')
            ->assertCreated()
            ->assertJson([
                'queue_number' => '001',
                'stage' => 'consultation',
                'status' => 'waiting',
            ]);

        $this->assertDatabaseHas('visits', [
            'id' => 1,
            'visit_date' => now()->toDateString(),
            'status' => 'waiting',
        ]);
        $this->assertDatabaseHas('queue_events', [
            'visit_id' => 1,
            'event' => QueueEvent::EVENT_CREATED,
        ]);
        $this->assertDatabaseCount('queue_controls', 0);

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson(['queue_number' => null, 'stage' => null]);

        $this->getJson('/api/queue/state')
            ->assertOk()
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting.0.queue_number', '001')
            ->assertJsonPath('stages.consultation.waiting.0.status', 'waiting');
    }

    public function test_each_issue_gets_the_next_daily_number(): void
    {
        $first = $this->postJson('/api/queue/visits')->assertCreated()->json();
        $second = $this->postJson('/api/queue/visits')->assertCreated()->json();

        $this->assertSame('001', $first['queue_number']);
        $this->assertSame('002', $second['queue_number']);
        $this->assertNotSame($first['id'], $second['id']);
        $this->assertDatabaseCount('visits', 2);
    }

    public function test_issued_visit_appears_on_today_page_and_ticket(): void
    {
        $visit = $this->postJson('/api/queue/visits')->assertCreated()->json();

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('001');
        $this->assertDatabaseCount('visits', 1);

        $this->get('/tickets/visits/'.$visit['id'])
            ->assertOk()
            ->assertSee('001')
            ->assertSee('التاريخ:')
            ->assertSee('print-btn', false)
            ->assertSee('طباعة التذكرة')
            ->assertDontSee('type="text"', false)
            ->assertDontSee('type="password"', false);
    }

    public function test_issue_button_is_guarded_against_double_click(): void
    {
        $this->get('/queue-number')
            ->assertOk()
            ->assertSee('js/issue-ticket.js');

        $script = file_get_contents(base_path('public/js/issue-ticket.js'));

        $this->assertStringContainsString('inFlight', $script);
        $this->assertStringContainsString('pageshow', $script);
        $this->assertStringContainsString("method: 'POST'", $script);
    }
}
