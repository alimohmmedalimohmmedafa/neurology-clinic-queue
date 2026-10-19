<?php

namespace Tests\Feature;

use App\Models\QueueControl;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReceptionUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_reception_page_renders_arabic_rtl_layout_with_all_controls(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('نظام الطابور — موظف الاستقبال')
            ->assertSee('طابور المقابلة الأول')
            ->assertSee('طابور النتيجة')
            ->assertSee('تسجيل زيارة جديدة')
            ->assertSee('بدء الطابور')
            ->assertSee('استدعاء التالي')
            ->assertSee('تخطي (عدم حضور)')
            ->assertSee('إنهاء الزيارة')
            ->assertSee('نقل إلى المراجعة')
            ->assertSee('إنهاء العمل')
            ->assertDontSee('إيقاف مؤقت')
            ->assertDontSee('استئناف');
    }

    public function test_queue_state_reports_empty_stages_without_writing(): void
    {
        $this->getJson('/api/queue/state')
            ->assertOk()
            ->assertJsonPath('stages.consultation.is_paused', false)
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting', [])
            ->assertJsonPath('stages.review.is_paused', false)
            ->assertJsonPath('stages.review.current', null)
            ->assertJsonPath('stages.review.waiting', []);

        $this->assertSame(0, QueueControl::count());
    }

    public function test_state_reflects_full_reception_flow(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/visits')->assertCreated();

        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.current.queue_number', '001')
            ->assertJsonPath('stages.consultation.current.status', 'called')
            ->assertJsonPath('stages.consultation.waiting.0.queue_number', '002')
            ->assertJsonPath('stages.consultation.waiting.0.status', 'waiting')
            ->assertJsonPath('stages.review.current', null);

        $this->postJson('/api/queue/visits/1/move-to-review')->assertOk();

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting.0.queue_number', '002')
            ->assertJsonPath('stages.review.waiting.0.queue_number', '001')
            ->assertJsonPath('stages.review.waiting.0.stage', 'review')
            ->assertJsonPath('stages.review.waiting.0.status', 'waiting');

        $this->postJson('/api/queue/stages/review/start')->assertOk();
        $this->postJson('/api/queue/visits/1/complete')->assertOk();

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.review.current', null)
            ->assertJsonPath('stages.review.waiting', [])
            ->assertJsonPath('stages.consultation.waiting.0.queue_number', '002');
    }

    public function test_skipped_visit_frees_the_stage_slot_in_state(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->postJson('/api/queue/visits/1/skip')->assertOk();

        $this->getJson('/api/queue/state')
            ->assertJsonPath('stages.consultation.current', null)
            ->assertJsonPath('stages.consultation.waiting', []);

        $this->postJson('/api/queue/stages/consultation/call-next')
            ->assertStatus(409)
            ->assertJson(['message' => 'No waiting visits in this queue.']);
    }

    public function test_state_contains_only_queue_fields_and_no_patient_data(): void
    {
        $this->postJson('/api/queue/visits')->assertCreated();
        $this->postJson('/api/queue/stages/consultation/start')->assertOk();

        $current = $this->getJson('/api/queue/state')->json('stages.consultation.current');

        $this->assertSame(
            ['id', 'queue_number', 'visit_date', 'stage', 'status', 'completed_at'],
            array_keys($current)
        );

        $waiting = $this->getJson('/api/queue/state')->json('stages.consultation.waiting');
        $this->assertSame([], $waiting);
    }
}
