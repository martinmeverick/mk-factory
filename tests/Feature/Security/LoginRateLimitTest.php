<?php

declare(strict_types=1);

namespace Tests\Feature\Security;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Chování limitu přihlášení (route middleware `throttle:login`).
 *
 * Testy ověřují pozorovatelné chování — kdo je zbrzděn, kdo ne a co dostane —
 * ne vnitřní konstanty limiteru. Cache je v sadě `array`, tedy izolovaná
 * per test (každý test bootuje novou aplikaci). Čas se posouvá přes
 * `travel()`, nikdy se reálně nespí.
 */
class LoginRateLimitTest extends TestCase
{
    use RefreshDatabase;

    private const IP_A = '198.51.100.10';

    private const IP_B = '198.51.100.20';

    protected function setUp(): void
    {
        parent::setUp();

        // Auth::attempt vyrovnává dobu odpovědi (Timebox); v testech nespíme.
        Sleep::fake();
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function attempt(string $ip, mixed $email, mixed $password = 'wrong-password', array $headers = []): TestResponse
    {
        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->post(route('login.attempt'), ['email' => $email, 'password' => $password], $headers);
    }

    /** Pět běžně zpracovaných (neúspěšných) pokusů pro jednu identitu z jedné IP. */
    private function exhaustIdentity(string $ip, string $email): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->attempt($ip, $email)
                ->assertStatus(302)
                ->assertSessionHasErrors('email');
        }
    }

    public function test_sixth_attempt_for_same_identity_gets_429_with_retry_after(): void
    {
        $this->exhaustIdentity(self::IP_A, 'user@example.com');

        $response = $this->attempt(self::IP_A, 'user@example.com', 'super-secret-password');

        $response->assertStatus(429);
        $response->assertHeader('Retry-After');

        $retryAfter = $response->headers->get('Retry-After');
        $this->assertMatchesRegularExpression('/^\d+$/', (string) $retryAfter);
        $this->assertGreaterThan(0, (int) $retryAfter);
        $this->assertLessThanOrEqual(60, (int) $retryAfter);

        // Obecná česká odpověď — bez echa e-mailu nebo hesla, bez informace o existenci účtu.
        $response->assertSee('Příliš mnoho pokusů o přihlášení');
        $response->assertDontSee('user@example.com');
        $response->assertDontSee('super-secret-password');
    }

    public function test_identity_bucket_is_released_after_the_window_passes(): void
    {
        $this->exhaustIdentity(self::IP_A, 'user@example.com');
        $this->attempt(self::IP_A, 'user@example.com')->assertStatus(429);

        $this->travel(61)->seconds();

        $this->attempt(self::IP_A, 'user@example.com')
            ->assertStatus(302)
            ->assertSessionHasErrors('email');
    }

    public function test_email_case_and_whitespace_variants_share_one_bucket(): void
    {
        for ($i = 1; $i <= 3; $i++) {
            $this->attempt(self::IP_A, 'User@Example.COM')->assertStatus(302);
        }
        for ($i = 1; $i <= 2; $i++) {
            $this->attempt(self::IP_A, '  user@example.com  ')->assertStatus(302);
        }

        $this->attempt(self::IP_A, 'user@example.com')->assertStatus(429);
    }

    public function test_many_distinct_emails_from_one_ip_hit_the_ip_cap(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->attempt(self::IP_A, "user{$i}@example.com")->assertStatus(302);
        }

        $this->attempt(self::IP_A, 'user-31@example.com')->assertStatus(429);

        // Jiná IP není zasažena.
        $this->attempt(self::IP_B, 'user-31@example.com')->assertStatus(302);
    }

    public function test_independent_ips_are_isolated_and_there_is_no_global_email_lock(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        // Útočník z IP A vyčerpá kbelík pro e-mail oběti.
        $this->exhaustIdentity(self::IP_A, 'owner@example.com');
        $this->attempt(self::IP_A, 'owner@example.com')->assertStatus(429);

        // Oběť ze své IP B se stále přihlásí — kbelík je vázaný na IP, ne jen na e-mail.
        $this->attempt(self::IP_B, 'owner@example.com', 'password')
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_forwarded_for_header_does_not_move_the_client_to_another_bucket(): void
    {
        $this->exhaustIdentity(self::IP_A, 'user@example.com');

        $this->attempt(self::IP_A, 'user@example.com', 'wrong-password', [
            'X-Forwarded-For' => '203.0.113.9',
        ])->assertStatus(429);
    }

    public function test_malformed_email_shapes_do_not_break_the_limiter(): void
    {
        $shapes = [
            ['email' => null, 'password' => 'x'],
            ['email' => '', 'password' => 'x'],
            ['email' => ['user@example.com'], 'password' => 'x'],
            ['email' => ['a' => 'b'], 'password' => 'x'],
            ['email' => 'user@example.com', 'password' => ['x']],
        ];

        foreach ($shapes as $payload) {
            $this->withServerVariables(['REMOTE_ADDR' => self::IP_A])
                ->post(route('login.attempt'), $payload)
                ->assertStatus(302)
                ->assertSessionHasErrors();
        }

        // Limiter po podivných vstupech dál běžně funguje.
        $this->attempt(self::IP_A, 'user@example.com')
            ->assertStatus(302)
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_login_page_stays_accessible_while_post_is_throttled(): void
    {
        $this->exhaustIdentity(self::IP_A, 'user@example.com');
        $this->attempt(self::IP_A, 'user@example.com')->assertStatus(429);

        $this->withServerVariables(['REMOTE_ADDR' => self::IP_A])
            ->get(route('login'))
            ->assertOk()
            ->assertSee('Přihlášení');
    }

    public function test_valid_login_under_limit_regenerates_session_and_redirects(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        $this->startSession();
        $originalSessionId = $this->app['session']->getId();

        $response = $this->withServerVariables(['REMOTE_ADDR' => self::IP_A])
            ->withCookie(config('session.cookie'), $originalSessionId)
            ->post(route('login.attempt'), [
                'email' => 'owner@example.com',
                'password' => 'password',
                'remember' => '1',
            ]);

        $response->assertRedirect(route('dashboard'));
        $response->assertCookie(Auth::guard('web')->getRecallerName());
        $this->assertAuthenticatedAs($user);
        $this->assertNotSame($originalSessionId, $this->app['session']->getId());
    }

    public function test_successful_attempts_count_and_correct_credentials_cannot_bypass_throttle(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);

        for ($i = 1; $i <= 4; $i++) {
            $this->attempt(self::IP_A, 'owner@example.com')->assertStatus(302);
        }

        // Pátý pokus je úspěšný — a počítá se také.
        $this->attempt(self::IP_A, 'owner@example.com', 'password')
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->withServerVariables(['REMOTE_ADDR' => self::IP_A])
            ->post(route('logout'))
            ->assertRedirect(route('login'));
        $this->assertGuest();

        // Šestý pokus se správným heslem už neprojde.
        $this->attempt(self::IP_A, 'owner@example.com', 'password')->assertStatus(429);
        $this->assertGuest();
    }
}
