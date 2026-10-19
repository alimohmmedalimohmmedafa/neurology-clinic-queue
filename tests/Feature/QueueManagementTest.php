<?php

namespace Tests\Feature;

use App\Enums\VisitStage;
use App\Enums\VisitStatus;
use App\Models\QueueControl;
use App\Models\QueueEvent;
use App\Models\Visit;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class QueueManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_registering_visits_assigns_daily_numbers_starting_from_001(): void
    {
        $first = $this->postJson('/api/queue/visits')->assertCreated();
        $second = $this->postJson('/api/queue/visits')->assertCreated();

        $first->assertJson(['queue_number' => '001', 'stage' => 'consultation', 'status' => 'waiting']);
        $second->assertJson(['queue_number' => '002']);

        $this->assertDatabaseHas('queue_events', [
            'visit_id' => 1,
            'event' => QueueEvent::EVENT_CREATED,
        ]);
    }

    public function test_queue_numbers_restart_every_day(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '001']);
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '002']);

        Carbon::setTestNow('2026-10-18 09:00:00');
        $this->postJson('/api/queue/visits')->assertCreated()->assertJson(['queue_number' => '001']);
    }

    public function test_start_calls_first_waiting_visit_automatically(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/stages/consultation/start')
            ->assertOk()
            ->assertJson(['queue_number' => '001', 'status' => 'called']);

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => 'called']);
        $this->assertDatabaseHas('visits', ['id' => 2, 'status' => 'waiting']);
        $this->assertDatabaseHas('queue_controls', ['stage' => 'consultation', 'current_visit_id' => 1]);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => QueueEvent::EVENT_CALLED]);
    }

    public function test_review_queue_starts_independently_from_consultation(): void
    {
        $this->postJson('/api/queue/visits', ['stage' => 'consultation']);
        $this->postJson('/api/queue/visits', ['stage' => 'review']);

        $this->postJson('/api/queue/stages/review/start')
            ->assertOk()
            ->assertJson(['queue_number' => '002', 'stage' => 'review', 'status' => 'called']);

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => 'waiting']);
        $this->assertDatabaseHas('visits', ['id' => 2, 'status' => 'called']);
    }

    public function test_call_next_is_blocked_while_a_visit_is_called(): void
    {
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertStatus(409)
            ->assertJson(['message' => 'Resolve the currently called visit before calling the next one.']);
    }

    public function test_next_visit_is_called_after_completing_the_current_one(): void
    {
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/complete')
            ->assertOk()
            ->assertJson(['status' => 'completed']);

        $this->assertNotNull(Visit::find(1)->completed_at);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => QueueEvent::EVENT_COMPLETED]);
        $this->assertNull(QueueControl::where('stage', 'consultation')->value('current_visit_id'));

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertOk()
            ->assertJson(['queue_number' => '002', 'status' => 'called']);
    }

    public function test_skipping_keeps_the_visit_record_and_calls_next(): void
    {
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/skip')
            ->assertOk()
            ->assertJson(['status' => 'skipped']);

        $this->assertDatabaseHas('visits', ['id' => 1, 'status' => 'skipped']);
        $this->assertDatabaseHas('queue_events', ['visit_id' => 1, 'event' => QueueEvent::EVENT_SKIPPED]);

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertOk()
            ->assertJson(['queue_number' => '002']);
    }

    public function test_moved_to_review_keeps_queue_number_and_reenters_review_queue(): void
    {
        Carbon::setTestNow('2026-10-17 09:00:00');
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/visits', ['stage' => 'review']);
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertOk()
            ->assertJson(['queue_number' => '001', 'stage' => 'review', 'status' => 'waiting']);

        $this->assertDatabaseHas('visits', [
            'id' => 1,
            'queue_number' => '001',
            'stage' => 'review',
            'status' => 'waiting',
            'visit_date' => '2026-10-17',
        ]);
        $this->assertSame(2, Visit::count());

        $this->assertDatabaseHas('queue_events', [
            'visit_id' => 1,
            'event' => QueueEvent::EVENT_MOVED_TO_REVIEW,
            'stage' => 'consultation',
        ]);
        $this->assertNull(QueueControl::where('stage', 'consultation')->value('current_visit_id'));

        $this->postJson('/api/queue/stages/review/call-next')
            ->assertOk()
            ->assertJson(['id' => 1, 'status' => 'called']);

        $this->assertDatabaseHas('visits', ['id' => 2, 'status' => 'waiting']);
    }

    public function test_moved_visit_leaves_the_consultation_queue_entirely(): void
    {
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertOk()
            ->assertJson(['id' => 2, 'status' => 'called']);

        $this->assertDatabaseHas('visits', ['id' => 1, 'stage' => 'review']);
    }

    public function test_move_to_review_is_idempotent_against_double_submission(): void
    {
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();
        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Visit is already in the review stage.']);

        $this->assertSame(1, QueueEvent::where('visit_id', 1)
            ->where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)
            ->count());

        $this->assertDatabaseHas('visits', ['id' => 1, 'stage' => 'review', 'status' => 'waiting']);
    }

    public function test_moving_a_missing_visit_returns_404(): void
    {
        $this->postJson('/api/queue/visits/999/move-to-review')
            ->assertNotFound();

        $this->assertSame(0, Visit::count());
    }

    public function test_completing_or_skipping_a_non_called_visit_is_rejected(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/visits/1/complete')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a called visit can be completed.']);

        $this->postJson('/api/queue/visits/1/skip')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a called visit can be skipped.']);
    }

    public function test_waiting_and_completed_visits_can_be_moved_to_review(): void
    {
        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertOk()
            ->assertJson(['id' => 1, 'queue_number' => '001', 'stage' => 'review', 'status' => 'waiting']);

        $this->postJson('/api/queue/visits');
        $this->postJson('/api/queue/visits/2/call')->assertOk();
        $this->postJson('/api/queue/visits/2/complete')->assertOk();

        $this->postJson('/api/queue/visits/2/move-to-review')
            ->assertOk()
            ->assertJson(['id' => 2, 'queue_number' => '002', 'stage' => 'review', 'status' => 'waiting']);

        $this->assertSame(2, Visit::count());
        $this->assertDatabaseHas('visits', ['id' => 2, 'stage' => 'review', 'status' => 'waiting', 'completed_at' => null]);
        $this->assertSame(2, QueueEvent::where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)->count());
    }

    public function test_skipped_and_other_day_visits_cannot_be_moved_to_review(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/skip')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only a waiting, called or completed visit can be moved to review.']);

        $old = Visit::create([
            'queue_number' => 9,
            'visit_date' => '2026-10-17',
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Completed,
        ]);

        $this->postJson('/api/queue/visits/'.$old->id.'/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Only today\'s visits can be moved to review.']);

        $this->assertDatabaseHas('visits', ['id' => $old->id, 'stage' => 'consultation']);
        $this->assertSame(0, QueueEvent::where('visit_id', $old->id)->where('event', QueueEvent::EVENT_MOVED_TO_REVIEW)->count());
    }

    public function test_moving_a_review_visit_again_and_invalid_stage_are_rejected(): void
    {
        $this->postJson('/api/queue/visits', ['stage' => 'review']);
        $this->postJson('/api/queue/stages/review/start')->assertOk();

        $this->postJson('/api/queue/visits/1/move-to-review')
            ->assertStatus(409)
            ->assertJson(['message' => 'Visit is already in the review stage.']);

        $this->postJson('/api/queue/stages/bogus/start')
            ->assertStatus(422)
            ->assertJson(['message' => 'Invalid stage. Allowed values: consultation, review.']);
    }

    public function test_call_next_on_empty_queue_is_rejected(): void
    {
        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertStatus(409)
            ->assertJson(['message' => 'No waiting visits in this queue.']);
    }

    public function test_database_index_blocks_second_called_visit_in_same_stage(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Called,
        ]);

        $this->expectException(QueryException::class);

        Visit::create([
            'queue_number' => 2,
            'visit_date' => '2026-10-17',
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Called,
        ]);
    }

    public function test_database_index_allows_one_called_visit_per_stage(): void
    {
        Visit::create([
            'queue_number' => 1,
            'visit_date' => '2026-10-17',
            'stage' => VisitStage::Consultation,
            'status' => VisitStatus::Called,
        ]);

        Visit::create([
            'queue_number' => 2,
            'visit_date' => '2026-10-17',
            'stage' => VisitStage::Review,
            'status' => VisitStatus::Called,
        ]);

        $this->assertSame(2, Visit::where('status', VisitStatus::Called->value)->count());
    }

    public function test_registering_many_visits_under_concurrent_style_failures_still_numbers_sequentially(): void
    {
        Carbon::setTestNow('2026-10-17 10:00:00');

        Visit::create(['queue_number' => 1, 'visit_date' => '2026-10-17']);

        // Simulate a stale read: the controller computes max+1 = 2 while
        // another request already took 2; the unique index rejects the
        // duplicate and the retry logic re-computes 3.
        $this->postJson('/api/queue/visits')
            ->assertCreated()
            ->assertJson(['queue_number' => '002']);

        DB::table('visits')->where('id', 2)->delete();

        $this->postJson('/api/queue/visits')
            ->assertCreated()
            ->assertJson(['queue_number' => '002']);
    }
}
