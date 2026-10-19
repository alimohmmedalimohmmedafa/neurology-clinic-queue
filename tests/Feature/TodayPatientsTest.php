<?php

namespace Tests\Feature;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\QueueControl;
use App\Models\QueueEvent;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TodayPatientsTest extends TestCase
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

    private function makeVisit(array $overrides = []): Visit
    {
        return Visit::create(array_merge([
            'queue_number' => 1,
            'visit_date' => now()->toDateString(),
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ], $overrides));
    }

    public function test_reception_page_links_to_today_patients_page(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/patients/today')
            ->assertSee('جميع مرضى اليوم');
    }

    public function test_page_lists_today_visits_with_required_columns_and_no_personal_data(): void
    {
        $this->makeVisit(['queue_number' => 1, 'status' => VisitStatus::Waiting]);
        $this->makeVisit(['queue_number' => 2, 'status' => VisitStatus::Called]);
        $this->makeVisit(['queue_number' => 3, 'stage' => VisitStage::Review, 'status' => VisitStatus::Skipped]);
        $this->makeVisit(['queue_number' => 4, 'status' => VisitStatus::Completed]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('رقم المريض')
            ->assertSee('المرحلة')
            ->assertSee('الحالة')
            ->assertSee('وقت التسجيل')
            ->assertSee('الإجراءات')
            ->assertSee('001')
            ->assertSee('002')
            ->assertSee('003')
            ->assertSee('004')
            ->assertSee('المقابلة')
            ->assertSee('النتيجة')
            ->assertSee('منتظر')
            ->assertSee('تم استدعاؤه')
            ->assertSee('تم تجاوزه')
            ->assertSee('مكتملة')
            ->assertSee('2026-10-18 10:30')
            ->assertDontSee('الاسم')
            ->assertDontSee('هاتف');

        $this->assertDatabaseCount('visits', 4);
    }

    public function test_page_does_not_show_visits_of_other_days(): void
    {
        $this->makeVisit(['queue_number' => 1]);
        $this->makeVisit(['queue_number' => 50, 'visit_date' => '2026-10-17']);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('001')
            ->assertDontSee('050');
    }

    public function test_page_reads_queue_state_without_creating_control_rows(): void
    {
        $this->makeVisit(['queue_number' => 1]);

        $this->get('/patients/today')->assertOk();

        $this->assertDatabaseCount('queue_controls', 0);
    }

    public function test_search_filters_by_queue_number(): void
    {
        $this->makeVisit(['queue_number' => 1]);
        $this->makeVisit(['queue_number' => 2]);
        $this->makeVisit(['queue_number' => 3]);

        $this->get('/patients/today?search=2')
            ->assertOk()
            ->assertSee('002')
            ->assertDontSee('001')
            ->assertDontSee('003');

        $this->get('/patients/today?search=003')
            ->assertOk()
            ->assertSee('003')
            ->assertDontSee('001')
            ->assertDontSee('002');

        $this->get('/patients/today?search=9')
            ->assertOk()
            ->assertSee('لا توجد زيارات مطابقة.');

        $this->get('/patients/today?search=abc')
            ->assertOk()
            ->assertSee('لا توجد زيارات مطابقة.');
    }

    public function test_stage_and_status_filters_narrow_the_results(): void
    {
        $this->makeVisit(['queue_number' => 1]);
        $this->makeVisit(['queue_number' => 2, 'status' => VisitStatus::Called]);
        $this->makeVisit(['queue_number' => 3, 'stage' => VisitStage::Review]);
        $this->makeVisit(['queue_number' => 4, 'stage' => VisitStage::Review, 'status' => VisitStatus::Completed]);

        $this->get('/patients/today?stage=review')
            ->assertOk()
            ->assertSee('003')
            ->assertSee('004')
            ->assertDontSee('001')
            ->assertDontSee('002');

        $this->get('/patients/today?status=waiting')
            ->assertOk()
            ->assertSee('001')
            ->assertSee('003')
            ->assertDontSee('002')
            ->assertDontSee('004');

        $this->get('/patients/today?stage=consultation&status=waiting')
            ->assertOk()
            ->assertSee('001')
            ->assertDontSee('002')
            ->assertDontSee('003')
            ->assertDontSee('004');

        $this->get('/patients/today?stage=bogus&status=bogus')
            ->assertOk()
            ->assertSee('001')
            ->assertSee('004');
    }

    public function test_waiting_visit_shows_call_and_transfer_actions(): void
    {
        $this->makeVisit(['queue_number' => 1]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('استدعاء المريض')
            ->assertSee('تحويل إلى طابور النتيجة')
            ->assertDontSee('إنهاء الزيارة')
            ->assertDontSee('إعادة إلى الانتظار')
            ->assertDontSee('لا توجد إجراءات');
    }

    public function test_called_consultation_visit_shows_complete_and_move_actions(): void
    {
        $visit = $this->makeVisit(['queue_number' => 1, 'status' => VisitStatus::Called]);
        QueueControl::create(['stage' => VisitStage::Consultation->value, 'current_visit_id' => $visit->id]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('إنهاء الزيارة')
            ->assertSee('تحويل إلى طابور النتيجة')
            ->assertDontSee('استدعاء المريض')
            ->assertDontSee('إعادة إلى الانتظار');
    }

    public function test_called_review_visit_shows_complete_and_reprint_actions(): void
    {
        $this->makeVisit([
            'queue_number' => 1,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Called,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('إنهاء الزيارة')
            ->assertSee('إعادة طباعة تذكرة النتيجة')
            ->assertDontSee('تحويل إلى طابور النتيجة')
            ->assertDontSee('استدعاء المريض')
            ->assertDontSee('إعادة إلى الانتظار');
    }

    public function test_skipped_visit_shows_only_the_restore_action(): void
    {
        $this->makeVisit(['queue_number' => 1, 'status' => VisitStatus::Skipped]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('إعادة إلى الانتظار')
            ->assertDontSee('استدعاء المريض')
            ->assertDontSee('إنهاء الزيارة')
            ->assertDontSee('تحويل إلى طابور النتيجة');
    }

    public function test_completed_consultation_visit_still_offers_the_transfer_action(): void
    {
        $this->makeVisit(['queue_number' => 1, 'status' => VisitStatus::Completed]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('تحويل إلى طابور النتيجة')
            ->assertDontSee('استدعاء المريض')
            ->assertDontSee('إنهاء الزيارة')
            ->assertDontSee('إعادة إلى الانتظار')
            ->assertDontSee('لا توجد إجراءات');
    }

    public function test_completed_review_visit_shows_no_transfer_action(): void
    {
        $this->makeVisit([
            'queue_number' => 1,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Completed,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('لا توجد إجراءات')
            ->assertDontSee('تحويل إلى طابور النتيجة')
            ->assertDontSee('استدعاء المريض');
    }

    public function test_call_action_disappears_while_the_stage_slot_is_taken(): void
    {
        $called = $this->makeVisit(['queue_number' => 1, 'status' => VisitStatus::Called]);
        QueueControl::create(['stage' => VisitStage::Consultation->value, 'current_visit_id' => $called->id]);
        $this->makeVisit(['queue_number' => 2]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertDontSee('استدعاء المريض');

        QueueControl::where('stage', VisitStage::Consultation->value)
            ->update(['current_visit_id' => null]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('استدعاء المريض');
    }

    public function test_call_endpoint_calls_the_selected_waiting_visit(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/2/call')
            ->assertStatus(409)
            ->assertJson(['message' => 'Another visit is already being called in this stage.']);

        $this->postJson('/api/queue/visits/1/complete')->assertOk();

        $this->postJson('/api/queue/visits/2/call')
            ->assertOk()
            ->assertJson(['queue_number' => '002', 'status' => 'called']);

        $this->assertDatabaseHas('queue_controls', ['stage' => 'consultation', 'current_visit_id' => 2]);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 2, 'event' => QueueEvent::EVENT_CALLED]);
        $this->assertDatabaseHas('display_state', ['current_visit_id' => 2, 'stage' => 'consultation']);

        $this->postJson('/api/queue/visits/1/call')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a waiting visit can be called.']);

        $this->postJson('/api/queue/visits/2/call')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a waiting visit can be called.']);
    }

    public function test_call_endpoint_rejects_visits_of_other_days(): void
    {
        $yesterday = $this->makeVisit(['queue_number' => 7, 'visit_date' => '2026-10-17']);

        $this->postJson('/api/queue/visits/'.$yesterday->id.'/call')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only today\'s visits can be called.']);
    }

    public function test_restored_visit_goes_back_to_waiting_then_can_be_called_and_completed(): void
    {
        $visit = $this->makeVisit(['queue_number' => 1, 'status' => VisitStatus::Skipped]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/restore')
            ->assertOk()
            ->assertJson(['status' => 'waiting', 'queue_number' => '001', 'stage' => 'consultation']);

        $this->assertDatabaseHas('visits', [
            'id' => $visit->id,
            'queue_number' => 1,
            'status' => 'waiting',
        ]);
        $this->assertDatabaseHas('queue_events', [
            'visit_id' => $visit->id,
            'event' => QueueEvent::EVENT_RESTORED,
        ]);

        $this->postJson('/api/queue/visits/'.$visit->id.'/call')
            ->assertOk()
            ->assertJson(['status' => 'called']);

        $this->postJson('/api/queue/visits/'.$visit->id.'/complete')
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        $this->assertDatabaseCount('visits', 1);
    }

    public function test_restore_endpoint_rejects_non_skipped_and_other_day_visits(): void
    {
        $waiting = $this->makeVisit(['queue_number' => 1]);
        $completed = $this->makeVisit(['queue_number' => 2, 'status' => VisitStatus::Completed]);
        $yesterday = $this->makeVisit([
            'queue_number' => 7,
            'visit_date' => '2026-10-17',
            'status' => VisitStatus::Skipped,
        ]);

        $this->postJson('/api/queue/visits/'.$waiting->id.'/restore')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a skipped visit can be restored.']);

        $this->postJson('/api/queue/visits/'.$completed->id.'/restore')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a skipped visit can be restored.']);

        $this->postJson('/api/queue/visits/'.$yesterday->id.'/restore')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only today\'s visits can be restored.']);

        $this->assertDatabaseHas('visits', ['id' => $waiting->id, 'status' => 'waiting']);
        $this->assertDatabaseHas('visits', ['id' => $completed->id, 'status' => 'completed']);
        $this->assertDatabaseHas('visits', ['id' => $yesterday->id, 'status' => 'skipped']);
    }

    public function test_completing_the_same_visit_twice_is_rejected(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/complete')->assertOk();

        $this->postJson('/api/queue/visits/1/complete')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a called visit can be completed.']);

        $this->assertDatabaseCount('visits', 1);
        $this->assertSame(1, QueueEvent::where('visit_id', 1)
            ->where('event', QueueEvent::EVENT_COMPLETED)
            ->count());
    }

    public function test_moved_visit_keeps_its_number_and_cannot_be_moved_twice(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertOk()
            ->assertJson(['queue_number' => '001', 'stage' => 'review', 'status' => 'waiting']);

        // A visit that is already in review is never transferred again, so it
        // can never get a second ticket.
        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Visit is already in the review stage.']);

        $this->postJson('/api/queue/stages/review/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Visit is already in the review stage.']);

        $this->assertDatabaseHas('visits', [
            'id' => 1,
            'queue_number' => 1,
            'stage' => 'review',
            'status' => 'called',
        ]);
        $this->assertSame(1, QueueEvent::where('visit_id', 1)
            ->where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)
            ->count());
    }
}
