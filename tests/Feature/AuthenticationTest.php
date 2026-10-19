<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected bool $actingAsByDefault = false;

    private function staff(array $overrides = []): User
    {
        return User::create(array_merge([
            'name' => 'موظف الاستقبال',
            'username' => 'staff',
            'email' => 'staff@staff.local',
            'password' => Hash::make('secret-pass'),
        ], $overrides));
    }

    public function test_login_page_is_arabic_and_right_to_left(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('dir="rtl"', false)
            ->assertSee('اسم المستخدم')
            ->assertSee('كلمة المرور')
            ->assertSee('تسجيل الدخول');
    }

    public function test_guest_cannot_open_the_reception_board_or_management_pages(): void
    {
        foreach (['/', '/queue-number', '/consultation-queue', '/review-queue', '/patients/today', '/tickets/visits/1'] as $page) {
            $this->get($page)->assertRedirect('/login');
        }
    }

    public function test_guest_cannot_reach_the_queue_management_api(): void
    {
        $this->getJson('/api/queue/state')->assertUnauthorized();
        $this->getJson('/api/queue/stages/consultation/visits')->assertUnauthorized();
        $this->postJson('/api/queue/visits')->assertUnauthorized();
        $this->postJson('/api/queue/stages/consultation/start')->assertUnauthorized();
        $this->postJson('/api/queue/end-work')->assertUnauthorized();
        $this->postJson('/api/queue/visits/1/complete')->assertUnauthorized();
    }

    public function test_valid_credentials_open_the_reception_board(): void
    {
        $this->staff();

        $this->post('/login', ['username' => 'staff', 'password' => 'secret-pass'])
            ->assertRedirect(route('reception'));

        $this->assertAuthenticated();
        $this->get('/')->assertOk();
    }

    public function test_wrong_password_is_rejected(): void
    {
        $this->staff();

        $this->from('/login')
            ->post('/login', ['username' => 'staff', 'password' => 'wrong-password'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_unknown_username_is_rejected(): void
    {
        $this->from('/login')
            ->post('/login', ['username' => 'nobody', 'password' => 'secret-pass'])
            ->assertRedirect('/login')
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    }

    public function test_login_requires_both_fields(): void
    {
        $this->from('/login')
            ->post('/login', ['username' => '', 'password' => ''])
            ->assertSessionHasErrors(['username', 'password']);

        $this->assertGuest();
    }

    public function test_logout_ends_the_session_and_blocks_protected_pages(): void
    {
        $user = $this->staff();

        $this->actingAs($user)->post('/logout')->assertRedirect('/login');

        $this->assertGuest();
        $this->get('/')->assertRedirect('/login');
    }

    public function test_signed_in_staff_is_redirected_away_from_the_login_page(): void
    {
        $this->actingAs($this->staff())->get('/login')->assertRedirect('/');
    }

    public function test_waiting_screen_is_public_and_exposes_no_admin_data(): void
    {
        $this->get('/waiting')->assertOk()->assertSee('شاشة العرض');

        $payload = $this->getJson('/api/queue/display')->assertOk()->json();

        $this->assertSame(
            ['visit_id', 'queue_number', 'stage', 'is_paused', 'call_token'],
            array_keys($payload)
        );

        // The public screen can never perform an administrative action.
        $this->getJson('/api/queue/state')->assertUnauthorized();
        $this->postJson('/api/queue/visits')->assertUnauthorized();
    }

    public function test_queue_and_ticket_functions_still_work_for_signed_in_staff(): void
    {
        $user = $this->staff();

        $visit = $this->actingAs($user)->postJson('/api/queue/visits')->assertCreated()->json();
        $this->actingAs($user)->postJson('/api/queue/stages/consultation/start')->assertOk();
        $this->actingAs($user)->postJson('/api/queue/visits/'.$visit['id'].'/complete')->assertOk();

        $this->actingAs($user)->get('/tickets/visits/'.$visit['id'])
            ->assertOk()
            ->assertSee('المقابلة الأول');
    }
}
