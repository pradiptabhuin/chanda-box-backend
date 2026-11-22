<?php

namespace Tests\Feature;

use App\Enums\TokenAbility;
use App\Models\Event;
use App\Models\EventMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * PUT /me (name, email) and PUT /me/password, plus marking every notification read.
 */
class ProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    protected function setUp(): void
    {
        parent::setUp();

        $this->me = User::factory()->create([
            'name' => 'Demo User',
            'phone' => '9876543210',
            'email' => 'demo@example.com',
            'email_verified_at' => now(),
            'password' => 'password',
        ]);
    }

    public function test_name_and_email_can_be_updated(): void
    {
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);

        $this->putJson('/api/v1/me', ['name' => '  Ananya Kumari Roy ', 'email' => 'Ananya@Example.com'])
            ->assertOk()
            ->assertJsonPath('data.name', 'Ananya Kumari Roy')
            ->assertJsonPath('data.email', 'ananya@example.com')
            ->assertJsonPath('data.phone', '9876543210');

        $this->me->refresh();
        $this->assertSame(['Ananya', 'Kumari', 'Roy'], [$this->me->first_name, $this->me->middle_name, $this->me->last_name]);
        $this->assertNull($this->me->email_verified_at, 'a new email is unverified');

        $this->putJson('/api/v1/me', ['name' => 'Ananya Roy', 'email' => null])->assertJsonPath('data.email', null);
    }

    public function test_profile_rules(): void
    {
        User::factory()->create(['email' => 'taken@example.com']);
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);

        $this->putJson('/api/v1/me', ['name' => 'A', 'email' => 'not-an-email'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email']);
        $this->putJson('/api/v1/me', ['name' => 'Demo User', 'email' => 'TAKEN@example.com'])
            ->assertJsonValidationErrors(['email' => 'already used']);
        // Keeping your own email is fine, and the phone can't be changed here.
        $this->putJson('/api/v1/me', ['name' => 'Demo User', 'email' => 'demo@example.com', 'phone' => '9800000000'])
            ->assertOk()
            ->assertJsonPath('data.phone', '9876543210');
        $this->assertNotNull($this->me->fresh()->email_verified_at);
    }

    public function test_changing_the_password_keeps_this_device_and_signs_out_the_others(): void
    {
        $thisPhone = $this->login();
        $otherPhone = $this->login();

        $this->withToken($thisPhone['token'])->putJson('/api/v1/me/password', [
            'current_password' => 'password',
            'password' => 'new-secret',
            'password_confirmation' => 'new-secret',
        ])->assertOk()->assertJsonPath('message', 'Password changed.');

        $this->assertTrue(Hash::check('new-secret', $this->me->fresh()->password));
        $this->app['auth']->forgetGuards();
        $this->withToken($thisPhone['token'])->getJson('/api/v1/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherPhone['token'])->getJson('/api/v1/me')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($otherPhone['refresh_token'])->postJson('/api/v1/auth/refresh')->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/auth/login', ['phone' => '9876543210', 'password' => 'new-secret'])->assertOk();
    }

    public function test_password_rules(): void
    {
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);

        $this->putJson('/api/v1/me/password', ['current_password' => 'wrong', 'password' => 'new-secret', 'password_confirmation' => 'new-secret'])
            ->assertJsonValidationErrors(['current_password' => 'incorrect']);
        $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => '123', 'password_confirmation' => '456'])
            ->assertJsonValidationErrors('password');
        $this->putJson('/api/v1/me/password', ['current_password' => 'password', 'password' => 'password', 'password_confirmation' => 'password'])
            ->assertJsonValidationErrors(['password' => 'different']);

        $this->assertTrue(Hash::check('password', $this->me->fresh()->password));
    }

    public function test_all_notifications_can_be_marked_read_at_once(): void
    {
        foreach (range(1, 3) as $i) {
            $this->me->notifications()->create([
                'id' => fake()->uuid(),
                'type' => 'invitation',
                'data' => ['title' => "Invite {$i}", 'body' => '...', 'data' => []],
            ]);
        }
        $other = User::factory()->create();
        $other->notifications()->create(['id' => fake()->uuid(), 'type' => 'invitation', 'data' => ['title' => 'x', 'body' => 'y', 'data' => []]]);
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);

        $this->postJson('/api/v1/notifications/read-all')->assertOk();

        $this->assertSame(0, $this->me->unreadNotifications()->count());
        $this->assertSame(1, $other->unreadNotifications()->count());
    }

    public function test_a_profile_photo_is_stored_replaced_and_removed(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);

        $first = $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.jpg', 600, 600)], ['Accept' => 'application/json'])
            ->assertOk()
            ->json('data.photo_url');
        $firstPath = $this->me->fresh()->profile_pic;
        $this->assertStringStartsWith("avatars/{$this->me->id}/", $firstPath);
        Storage::disk('public')->assertExists($firstPath);
        $this->assertSame(Storage::disk('public')->url($firstPath), $first);

        // A new upload gets a new name (no stale caches) and the old file is deleted.
        $this->post('/api/v1/me/photo', ['photo' => UploadedFile::fake()->image('me.png', 400, 400)], ['Accept' => 'application/json'])->assertOk();
        $secondPath = $this->me->fresh()->profile_pic;
        $this->assertNotSame($firstPath, $secondPath);
        Storage::disk('public')->assertMissing($firstPath);
        Storage::disk('public')->assertExists($secondPath);

        $this->getJson('/api/v1/me')->assertJsonPath('data.photo_url', Storage::disk('public')->url($secondPath));

        $this->deleteJson('/api/v1/me/photo')->assertOk()->assertJsonPath('data.photo_url', null);
        Storage::disk('public')->assertMissing($secondPath);
        $this->assertNull($this->me->fresh()->profile_pic);
    }

    public function test_only_reasonable_images_are_accepted(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->me, [TokenAbility::AccessApi->value]);
        $upload = fn ($file) => $this->post('/api/v1/me/photo', ['photo' => $file], ['Accept' => 'application/json']);

        $upload(UploadedFile::fake()->create('cv.pdf', 100, 'application/pdf'))->assertJsonValidationErrors('photo');
        $upload(UploadedFile::fake()->image('tiny.jpg', 50, 50))->assertJsonValidationErrors('photo');
        $upload(UploadedFile::fake()->image('huge.jpg', 1000, 1000)->size(6000))->assertJsonValidationErrors(['photo' => '5 MB']);
        $this->postJson('/api/v1/me/photo')->assertJsonValidationErrors('photo');

        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertNull($this->me->fresh()->profile_pic);
    }

    public function test_other_people_see_the_photo_on_events(): void
    {
        Storage::fake('public');
        $this->me->update(['profile_pic' => 'avatars/1/me.jpg']);
        $member = User::factory()->create();
        $event = Event::factory()->for($this->me, 'owner')->create();
        EventMember::factory()->owner()->for($event)->create(['user_id' => $this->me->id, 'phone' => $this->me->phone]);
        EventMember::factory()->for($event)->create(['user_id' => $member->id, 'phone' => $member->phone]);
        Sanctum::actingAs($member, [TokenAbility::AccessApi->value]);

        $url = Storage::disk('public')->url('avatars/1/me.jpg');
        $this->getJson("/api/v1/events/{$event->id}")->assertJsonPath('data.owner.photo_url', $url);
        $this->getJson("/api/v1/events/{$event->id}/members")
            ->assertJsonPath('data.0.photo_url', $url)
            ->assertJsonPath('data.1.photo_url', null);
    }

    /**
     * @return array<string, mixed>
     */
    private function login(): array
    {
        return $this->postJson('/api/v1/auth/login', ['phone' => '9876543210', 'password' => 'password'])
            ->assertOk()
            ->json();
    }
}
