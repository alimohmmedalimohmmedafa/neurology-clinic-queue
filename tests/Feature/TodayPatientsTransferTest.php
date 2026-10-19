<?php

namespace Tests\Feature;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\DisplayState;
use App\Models\QueueControl;
use App\Models\QueueEvent;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayPatientsTransferTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow('2026-10-18 10:30:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function calledConsultationVisit(int $number = 7): Visit
    {
        $visit = Visit::create([
            'queue_number' => $number,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Called,
        ]);

        QueueControl::create([
            'stage' => VisitStage::Consultation->value,
            'current_visit_id' => $visit->id,
        ]);

        return $visit;
    }

    public function test_table_offers_a_clear_transfer_button_for_consultation_visits(): void
    {
        $this->calledConsultationVisit();

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('تحويل إلى طابور النتيجة')
            ->assertSee('move-to-review', false);
    }

    public function test_waiting_visits_are_offered_the_transfer_button(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('تحويل إلى طابور النتيجة')
            ->assertSee('move-to-review', false);
    }

    public function test_completed_visits_are_offered_the_transfer_button(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Completed,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('تحويل إلى طابور النتيجة')
            ->assertSee('move-to-review', false);
    }

    public function test_skipped_visits_are_not_offered_the_transfer_button(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Skipped,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('إعادة إلى الانتظار')
            ->assertDontSee('تحويل إلى طابور النتيجة');
    }

    public function test_review_visits_are_not_offered_the_transfer_button(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('إعادة طباعة تذكرة النتيجة')
            ->assertDontSee('تحويل إلى طابور النتيجة');
    }

    public function test_transfer_accepts_a_waiting_visit_of_today(): void
    {
        $visit = Visit::create([
            'queue_number' => 4,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')
            ->assertOk()
            ->assertJsonPath('id', $visit->id)
            ->assertJsonPath('queue_number', '004')
            ->assertJsonPath('stage', 'review')
            ->assertJsonPath('status', 'waiting');

        $this->assertSame(1, Visit::count());
        $this->assertSame(1, QueueEvent::where('visit_id', $visit->id)
            ->where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)
            ->count());
    }

    public function test_transfer_accepts_a_completed_visit_and_keeps_its_log(): void
    {
        $visit = $this->calledConsultationVisit(12);
        $visit->update(['status' => VisitStatus::Completed, 'completed_at' => now()]);
        $visit->events()->create(['event' => QueueEvent::EVENT_COMPLETED, 'stage' => VisitStage::Consultation]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')
            ->assertOk()
            ->assertJsonPath('id', $visit->id)
            ->assertJsonPath('queue_number', '012')
            ->assertJsonPath('stage', 'review')
            ->assertJsonPath('status', 'waiting')
            ->assertJsonPath('completed_at', null);

        $moved = $visit->fresh();
        $this->assertSame(VisitStage::Review, $moved->stage);
        $this->assertSame(VisitStatus::Waiting, $moved->status);
        $this->assertSame('012', $moved->formattedQueueNumber());
        $this->assertNull($moved->completed_at);
        $this->assertSame(1, Visit::count());

        // The completion record stays in the log next to the new transfer event.
        $this->assertSame(1, QueueEvent::where('visit_id', $visit->id)->where('event', QueueEvent::EVENT_COMPLETED)->count());
        $this->assertSame(1, QueueEvent::where('visit_id', $visit->id)->where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)->count());
    }

    public function test_completed_visit_transfer_never_calls_the_patient(): void
    {
        $visit = $this->calledConsultationVisit(5);
        $visit->update(['status' => VisitStatus::Completed, 'completed_at' => now()]);
        QueueControl::where('stage', VisitStage::Consultation->value)->update(['current_visit_id' => null]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();

        $this->assertNull(QueueControl::where('stage', VisitStage::Review->value)->first()?->current_visit_id);
        $this->assertSame(0, QueueEvent::where('visit_id', $visit->id)->where('event', QueueEvent::EVENT_CALLED)->count());
    }

    public function test_transfer_rejects_visits_that_are_not_of_today(): void
    {
        $old = Visit::create([
            'queue_number' => 9,
            'visit_date' => now()->subDay()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Completed,
        ]);

        $this->postJson('/api/queue/visits/'.$old->id.'/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only today\'s visits can be moved to review.']);

        $this->assertDatabaseHas('visits', ['id' => $old->id, 'stage' => 'consultation']);
        $this->assertSame(0, QueueEvent::where('visit_id', $old->id)->count());
    }

    public function test_transfer_rejects_a_skipped_visit_and_keeps_restore_only(): void
    {
        $visit = Visit::create([
            'queue_number' => 3,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Skipped,
        ]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a waiting, called or completed visit can be moved to review.']);

        $this->assertDatabaseHas('visits', ['id' => $visit->id, 'stage' => 'consultation', 'status' => 'skipped']);

        $this->postJson('/api/queue/visits/'.$visit->id.'/restore')
            ->assertOk()
            ->assertJsonPath('status', 'waiting');

        $this->assertSame(1, Visit::count());
    }

    public function test_transfer_rejects_a_completed_review_visit(): void
    {
        $visit = Visit::create([
            'queue_number' => 6,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Completed,
        ]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Visit is already in the review stage.']);

        $this->assertSame(0, QueueEvent::where('visit_id', $visit->id)->where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)->count());
    }

    public function test_converting_a_completed_visit_prints_the_exam_number(): void
    {
        $visit = $this->calledConsultationVisit(11);
        $visit->update(['status' => VisitStatus::Completed, 'completed_at' => now()]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();

        $this->get('/tickets/visits/'.$visit->id.'?review=1')
            ->assertOk()
            ->assertSee('<div class="service">النتيجة</div>', false)
            ->assertSee('<div class="queue-number">F-001</div>', false)
            ->assertDontSee('مراجعة الطبيب بعد النتيجة');
    }

    public function test_transfer_moves_the_same_visit_into_the_review_queue(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')
            ->assertOk()
            ->assertJsonPath('id', $visit->id)
            ->assertJsonPath('queue_number', '007')
            ->assertJsonPath('stage', 'review')
            ->assertJsonPath('status', 'waiting');

        $this->assertSame(1, Visit::count());

        $moved = $visit->fresh();
        $this->assertSame(VisitStage::Review, $moved->stage);
        $this->assertSame(VisitStatus::Waiting, $moved->status);
        $this->assertSame('007', $moved->formattedQueueNumber());
        $this->assertSame(now()->toDateString(), $moved->visit_date->toDateString());

        $this->assertDatabaseHas('queue_events', [
            'visit_id' => $visit->id,
            'event' => QueueEvent::EVENT_MOVED_TO_REVIEW,
        ]);
    }

    public function test_transfer_frees_the_consultation_slot_and_never_calls_the_patient(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();

        $this->assertNull(
            QueueControl::where('stage', VisitStage::Consultation->value)->first()?->current_visit_id
        );
        $this->assertNull(
            QueueControl::where('stage', VisitStage::Review->value)->first()?->current_visit_id
        );
        $this->assertDatabaseMissing('queue_events', [
            'visit_id' => $visit->id,
            'event' => QueueEvent::EVENT_CALLED,
        ]);
        $this->assertSame(0, DisplayState::where('current_visit_id', $visit->id)->count());
    }

    public function test_transferred_visit_stays_in_review_only_once(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();

        $this->get('/api/queue/stages/consultation/visits')
            ->assertOk()
            ->assertJsonCount(0, 'visits');

        $this->get('/api/queue/stages/review/visits')
            ->assertOk()
            ->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.id', $visit->id)
            ->assertJsonPath('visits.0.status', 'waiting');

        $this->assertSame(1, Visit::where('stage', VisitStage::Review->value)->count());
    }

    public function test_repeated_transfer_is_rejected_and_issues_nothing(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();
        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertStatus(409);

        $this->assertSame(1, Visit::count());
        $this->assertSame(
            1,
            QueueEvent::where('visit_id', $visit->id)
                ->where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)
                ->count()
        );
    }

    public function test_review_ticket_shows_the_exam_number_and_review_wording(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();

        $this->get('/tickets/visits/'.$visit->id.'?review=1')
            ->assertOk()
            ->assertSee('<div class="service">النتيجة</div>', false)
            ->assertSee('<div class="queue-number">F-001</div>', false)
            ->assertDontSee('مراجعة الطبيب بعد النتيجة')
            ->assertSee('التاريخ: '.now()->toDateString());
    }

    public function test_other_tickets_never_show_the_review_entry_wording(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->get('/tickets/visits/'.$visit->id)
            ->assertOk()
            ->assertSee('المقابلة الأول')
            ->assertDontSee('<div class="service">النتيجة</div>', false);
    }

    public function test_today_page_carries_the_hidden_print_area_and_reprint_action(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('body class="today-page"', false)
            ->assertSee('css/ticket.css')
            ->assertSee('id="ticket-print"', false)
            ->assertSee('data-action-type="print"', false)
            ->assertSee('إعادة طباعة تذكرة النتيجة')
            ->assertDontSee('تحويل إلى طابور النتيجة');
    }

    public function test_print_script_prints_in_place_without_a_second_page(): void
    {
        $script = file_get_contents(public_path('js/today-patients.js'));

        $this->assertStringContainsString("fetch('/tickets/visits/' + visitId + '?review=1'", $script);
        $this->assertStringContainsString('window.print()', $script);
        $this->assertStringContainsString("doc.querySelector('main.ticket')", $script);
        $this->assertStringContainsString("getElementById('ticket-print')", $script);
        $this->assertStringNotContainsString('window.open', $script);
        $this->assertStringNotContainsString('target="_blank"', $script);
    }

    public function test_print_script_locks_the_button_against_repeated_clicks(): void
    {
        $script = file_get_contents(public_path('js/today-patients.js'));

        $this->assertSame(1, substr_count($script, "fetch(API_BASE + '/visits/'"));
        $this->assertSame(1, substr_count($script, "method: 'POST'"));

        preg_match('/function runAction\(button\).*?\n    \}/s', $script, $match);

        $this->assertNotEmpty($match, 'runAction is missing.');
        $this->assertStringContainsString('if (inFlight)', $match[0]);
        $this->assertStringContainsString('inFlight = true', $match[0]);
        $this->assertStringContainsString('setBusy(true)', $match[0]);
        $this->assertStringContainsString('setBusy(false)', $match[0]);
    }

    public function test_reprint_action_never_transfers_or_creates_a_visit(): void
    {
        $visit = $this->calledConsultationVisit();

        $this->postJson('/api/queue/visits/'.$visit->id.'/move-to-review')->assertOk();

        $before = QueueEvent::count();

        $this->get('/tickets/visits/'.$visit->id.'?review=1')->assertOk();
        $this->get('/tickets/visits/'.$visit->id.'?review=1')->assertOk();

        $this->assertSame($before, QueueEvent::count());
        $this->assertSame(1, Visit::count());
        $this->assertSame('007', $visit->fresh()->formattedQueueNumber());
    }
}
