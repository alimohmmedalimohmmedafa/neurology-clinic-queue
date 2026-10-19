<?php

namespace Tests\Feature;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReviewQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_review_queue_page_renders(): void
    {
        $this->get('/review-queue')
            ->assertOk()
            ->assertSee('طابور النتيجة')
            ->assertSee('بدء الطابور')
            ->assertSee('استدعاء التالي')
            ->assertSee('js/stage-queue.js')
            ->assertSee('data-stage="review"', false);
    }

    public function test_review_queue_only_finishes_visits_without_a_move_action(): void
    {
        $this->get('/review-queue')
            ->assertOk()
            ->assertSee('data-allow-move="false"', false)
            ->assertDontSee('نقل إلى النتيجة');

        $this->get('/consultation-queue')
            ->assertOk()
            ->assertSee('data-allow-move="true"', false);
    }

    public function test_sidebar_links_to_review_queue(): void
    {
        foreach (['/', '/patients/today', '/queue-number', '/consultation-queue'] as $page) {
            $this->get($page)
                ->assertOk()
                ->assertSee('/review-queue')
                ->assertSee('طابور النتيجة');
        }
    }

    public function test_stage_visits_lists_only_review_visits(): void
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

        $this->getJson('/api/queue/stages/review/visits')
            ->assertOk()
            ->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.queue_number', '002')
            ->assertJsonPath('visits.0.status', 'waiting')
            ->assertJsonPath('stage', 'review');
    }

    public function test_start_queue_calls_the_first_waiting_review_automatically(): void
    {
        $today = Carbon::today()->toDateString();

        foreach ([3, 4] as $number) {
            Visit::create([
                'visit_date' => $today,
                'queue_number' => $number,
                'stage' => VisitStage::Review,
                'status' => VisitStatus::Waiting,
            ]);
        }

        $this->postJson('/api/queue/stages/review/start')
            ->assertOk()
            ->assertJson(['queue_number' => '003', 'status' => 'called', 'stage' => 'review']);

        $this->assertEquals(1, Visit::where('status', VisitStatus::Called)->count());
    }

    public function test_call_next_is_rejected_while_a_review_visit_is_called(): void
    {
        $today = Carbon::today()->toDateString();

        foreach ([1, 2] as $number) {
            Visit::create([
                'visit_date' => $today,
                'queue_number' => $number,
                'stage' => VisitStage::Review,
                'status' => VisitStatus::Waiting,
            ]);
        }

        $this->postJson('/api/queue/stages/review/start')->assertOk();

        $this->postJson('/api/queue/stages/review/call-next')
            ->assertStatus(409);

        $this->assertEquals(1, Visit::where('status', VisitStatus::Called)->count());
    }

    public function test_completing_the_current_review_frees_the_stage_for_the_next(): void
    {
        $today = Carbon::today()->toDateString();

        foreach ([1, 2] as $number) {
            Visit::create([
                'visit_date' => $today,
                'queue_number' => $number,
                'stage' => VisitStage::Review,
                'status' => VisitStatus::Waiting,
            ]);
        }

        $this->postJson('/api/queue/stages/review/start')->assertOk();
        $this->postJson('/api/queue/visits/1/complete')->assertOk();

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => VisitStatus::Completed]);
        $this->assertEquals(0, Visit::where('status', VisitStatus::Called)->count());

        $this->postJson('/api/queue/stages/review/call-next')
            ->assertOk()
            ->assertJson(['queue_number' => '002', 'status' => 'called']);
    }

    public function test_review_queue_never_calls_a_consultation_visit(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 1,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/review/start')->assertStatus(409);

        $this->assertDatabaseHas('visits', [
            'id' => 1,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);
    }

    public function test_display_shows_the_review_stage_on_call(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 6,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/review/start')->assertOk();

        $this->getJson('/api/queue/display')
            ->assertOk()
            ->assertJson(['queue_number' => '006', 'stage' => 'review']);
    }

    public function test_transfer_from_consultation_to_review_is_manual(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 5,
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Waiting,
        ]);

        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->getJson('/api/queue/stages/review/visits')
            ->assertOk()
            ->assertJsonCount(0, 'visits');

        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $this->getJson('/api/queue/stages/review/visits')
            ->assertOk()
            ->assertJsonCount(1, 'visits')
            ->assertJsonPath('visits.0.queue_number', '005')
            ->assertJsonPath('visits.0.status', 'waiting')
            ->assertJsonPath('stage', 'review');

        $this->assertDatabaseHas('visits', [
            'id' => 1,
            'queue_number' => 5,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Waiting,
        ]);
    }

    public function test_today_page_shows_review_visits_with_the_correct_stage_and_status(): void
    {
        $today = Carbon::today()->toDateString();

        Visit::create([
            'visit_date' => $today,
            'queue_number' => 4,
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Called,
        ]);

        $this->get('/patients/today')
            ->assertOk()
            ->assertSee('004')
            ->assertSee('النتيجة')
            ->assertSee('تم استدعاؤه');
    }
}
