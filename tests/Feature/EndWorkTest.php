<?php

namespace Tests\Feature;

use App\Models\QueueEvent;
use App\Models\Visit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class EndWorkTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_reception_page_shows_end_work_button_and_confirmation_dialog(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('id="btn-end-work"', false)
            ->assertSee('id="end-work-modal"', false)
            ->assertSee('إنهاء العمل')
            ->assertSee('تأكيد إنهاء العمل')
            ->assertSee('بدء العمل من الرقم 001 في يوم العمل الجديد')
            ->assertSee('لا يمكن التراجع عن هذه العملية');
    }

    public function test_end_work_clears_the_waiting_screen_and_frees_both_stages(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJsonPath('queue_number', '001');

        $this->postJson('/api/queue/end-work')
            ->assertOk()
            ->assertJsonPath('closed_visits', 2)
            ->assertJsonPath('already_ended', false);

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.is_paused', false)
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting', [])
            ->assertJsonPath('stages.review.is_paused', false)
            ->assertJsonPath('stages.review.current', null)
            ->assertJsonPath('stages.review.waiting', []);

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJsonPath('visit_id', null)
            ->assertJsonPath('queue_number', null)
            ->assertJsonPath('stage', null);
    }

    public function test_end_work_keeps_visit_and_event_records(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $visitsBefore = Visit::count();
        $eventsBefore = QueueEvent::count();

        $this->postJson('/api/queue/end-work')->assertOk();

        $this->assertSame($visitsBefore, Visit::count());
        $this->assertSame($eventsBefore + 2, QueueEvent::count());

        $this->assertDatabaseHas('visits', ['id' => 1, 'queue_number' => 1, 'stage' => 'review', 'status' => 'skipped']);
        $this->assertDatabaseHas('visits', ['id' => 2, 'queue_number' => 2, 'stage' => 'consultation', 'status' => 'skipped']);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => 'session_closed']);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 2, 'event' => 'session_closed']);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => 'moved_to_review']);
    }

    public function test_closed_visits_do_not_reappear_in_the_active_queues(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/end-work')->assertOk();

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.waiting', [])
            ->assertJsonPath('stages.consultation.current', null);

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertStatus(409)
            ->assertJson(['message' => 'No waiting visits in this queue.']);
    }

    public function test_first_visit_of_the_next_work_day_starts_at_001(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/end-work')->assertOk();

        $this->travelTo(now()->addDay()->setTime(8, 0));

        $this->postJson('/api/queue/visits')
            ->assertCreated()
            ->assertJsonPath('queue_number', '001')
            ->assertJsonPath('status', 'waiting');

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.waiting.0.queue_number', '001');
    }

    public function test_called_slot_is_freed_so_the_new_day_can_call_again(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/end-work')->assertOk();

        $this->travelTo(now()->addDay()->setTime(8, 0));

        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertOk()
            ->assertJsonPath('queue_number', '001')
            ->assertJsonPath('status', 'called');
    }

    public function test_repeated_end_work_is_safe_and_reports_already_ended(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/end-work')
            ->assertOk()
            ->assertJsonPath('closed_visits', 1);

        $this->postJson('/api/queue/end-work')
            ->assertOk()
            ->assertJsonPath('closed_visits', 0)
            ->assertJsonPath('already_ended', true);

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.is_paused', false)
            ->assertJsonPath('stages.review.is_paused', false)
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting', []);

        $this->assertDatabaseCount('visits', 1);
    }

    public function test_end_work_on_a_quiet_day_closes_nothing_and_reports_already_ended(): void
    {
        $this->postJson('/api/queue/end-work')
            ->assertOk()
            ->assertJsonPath('closed_visits', 0)
            ->assertJsonPath('already_ended', true);

        $this->assertDatabaseCount('visits', 0);
    }
}
