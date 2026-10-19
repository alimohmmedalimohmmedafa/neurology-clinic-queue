<?php

namespace Tests\Feature;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsultationQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_consultation_queue_page_renders(): void
    {
        $this->get('/consultation-queue')
            ->assertOk()
            ->assertSee('طابور المقابلة')
            ->assertSee('بدء الطابور')
            ->assertSee('استدعاء التالي')
            ->assertSee('js/stage-queue.js');
    }

    public function test_sidebar_links_to_consultation_queue(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('/consultation-queue')
            ->assertSee('طابور المقابلة');

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('/consultation-queue');

        $this->get('/queue-number')
            ->assertOk()
            ->assertSee('/consultation-queue');
    }

    public function test_stage_visits_lists_only_consultation_visits(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 1,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);
        Visit::create([
            'visit_date' => $today,
            'queue_number' => 2,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);

        $this->getJson('/api/queue/stages/consultation/visits')
            ->assertOk()
            ->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.queue_number', '001')
            ->assertJsonPath('visits.0.status', 'waiting')
            ->assertJsonPath('stage', 'consultation');
    }

    public function test_start_queue_calls_the_first_waiting_consultation_automatically(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 1,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);
        Visit::create([
            'visit_date' => $today,
            'queue_number' => 2,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/consultation/start')
            ->assertOk()
            ->assertJson(['queue_number' => '001', 'status' => 'called']);

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => VisitStatus::Called]);
        $this->assertDatabaseHas('visits', ['id' => 2, 'status' => VisitStatus::Waiting]);
    }

    public function test_call_next_is_rejected_while_a_visit_is_called(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 1,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);
        Visit::create([
            'visit_date' => $today,
            'queue_number' => 2,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertStatus(409)
            ->assertJson(['message' => 'Resolve the currently called visit before calling the next one.']);

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => VisitStatus::Called]);
        $this->assertDatabaseHas('visits', ['id' => 2, 'status' => VisitStatus::Waiting]);
    }

    public function test_completing_the_current_visit_frees_the_stage_and_rejects_a_second_called(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 1,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);
        Visit::create([
            'visit_date' => $today,
            'queue_number' => 2,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/complete')->assertOk();

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => VisitStatus::Completed]);
        $this->assertEquals(0, Visit::where('status', VisitStatus::Called)->count());

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertOk()
            ->assertJson(['queue_number' => '002', 'status' => 'called']);

        $this->assertEquals(1, Visit::where('status', VisitStatus::Called)->count());
    }

    public function test_move_to_review_keeps_the_same_number_and_targets_review_only(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 7,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $this->assertDatabaseHas('visits', [
            'id' => 1,
            'queue_number' => 7,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);

        $this->getJson('/api/queue/stages/consultation/visits')
            ->assertOk()
            ->assertJsonCount(0, 'visits');
    }

    public function test_consultation_queue_never_calls_a_review_visit(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 1,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/consultation/start')->assertStatus(409);

        $this->assertDatabaseHas('visits', [
            'id' => 1,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);
    }

    public function test_calling_updates_the_display_screen_with_the_consultation_stage(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 4,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson(['queue_number' => '004', 'stage' => 'consultation']);
    }

    public function test_today_page_shows_the_correct_statuses(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 1,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);
        Visit::create([
            'visit_date' => $today,
            'queue_number' => 2,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Called,
        ]);
        Visit::create([
            'visit_date' => $today,
            'queue_number' => 3,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Completed,
        ]);
        Visit::create([
            'visit_date' => $today,
            'queue_number' => 4,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('001')
            ->assertSee('منتظر')
            ->assertSee('تم استدعاؤه')
            ->assertSee('مكتملة');
    }
}
