<?php

namespace Tests\Feature;

use App\Models\House;
use App\Models\Media;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Owner-managed listing media: image/video uploads, external video links, the
 * ownership guard, and how the public listing endpoints surface them.
 */
class MediaUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private House $house;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);

        $this->house = House::factory()->forOwner($this->owner)->create(['status' => 'active']);
    }

    public function test_owner_can_upload_an_image_to_their_house(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->owner);

        $response = $this->post('/api/owner/houses/'.$this->house->id.'/media', [
            'type' => 'image',
            'file' => UploadedFile::fake()->image('house.jpg', 1200, 800),
        ])->assertCreated();

        $this->assertSame('image', $response->json('data.type'));
        $this->assertNotEmpty($response->json('data.url'));

        $media = Media::firstOrFail();
        $this->assertSame(House::class, $media->mediable_type);
        $this->assertSame($this->house->id, $media->mediable_id);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_owner_can_upload_a_video_file(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->owner);

        $response = $this->post('/api/owner/houses/'.$this->house->id.'/media', [
            'type' => 'video',
            'file' => UploadedFile::fake()->create('tour.mp4', 2048, 'video/mp4'),
        ])->assertCreated();

        $this->assertSame('video', $response->json('data.type'));
        $media = Media::firstOrFail();
        $this->assertSame('video', $media->type);
        $this->assertNull($media->url);
        Storage::disk('public')->assertExists($media->path);
    }

    public function test_owner_can_add_a_video_by_url(): void
    {
        Sanctum::actingAs($this->owner);

        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ';

        $this->postJson('/api/owner/houses/'.$this->house->id.'/media', [
            'type' => 'video',
            'url' => $url,
        ])->assertCreated()->assertJsonPath('data.type', 'video')
            ->assertJsonPath('data.url', $url);

        $this->assertDatabaseHas('media', [
            'mediable_type' => House::class,
            'mediable_id' => $this->house->id,
            'type' => 'video',
            'url' => $url,
            'path' => null,
        ]);
    }

    public function test_owner_can_upload_media_to_their_room(): void
    {
        Storage::fake('public');
        $room = Room::factory()->forHouse($this->house)->create();

        Sanctum::actingAs($this->owner);

        $this->post('/api/owner/rooms/'.$room->id.'/media', [
            'file' => UploadedFile::fake()->image('room.jpg'),
        ])->assertCreated();

        $this->assertDatabaseHas('media', [
            'mediable_type' => Room::class,
            'mediable_id' => $room->id,
            'type' => 'image',
        ]);
    }

    public function test_a_different_owner_cannot_upload_to_another_owners_house(): void
    {
        $intruder = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);

        Sanctum::actingAs($intruder);

        $this->post('/api/owner/houses/'.$this->house->id.'/media', [
            'file' => UploadedFile::fake()->image('house.jpg'),
        ])->assertNotFound();

        $this->assertDatabaseCount('media', 0);
    }

    public function test_a_different_owner_cannot_delete_another_owners_media(): void
    {
        $media = Media::factory()->forHouse($this->house)->create();

        $intruder = User::factory()->owner()->create([
            'must_change_password' => false,
            'is_active' => true,
        ]);
        Sanctum::actingAs($intruder);

        $this->deleteJson('/api/owner/media/'.$media->id)->assertNotFound();
        $this->assertDatabaseHas('media', ['id' => $media->id]);
    }

    public function test_owner_can_delete_their_media_and_the_file_is_removed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('houses/photo.jpg', 'fake-bytes');

        $media = Media::factory()->forHouse($this->house)->create(['path' => 'houses/photo.jpg']);

        Sanctum::actingAs($this->owner);

        $this->deleteJson('/api/owner/media/'.$media->id)->assertOk();

        $this->assertDatabaseMissing('media', ['id' => $media->id]);
        Storage::disk('public')->assertMissing('houses/photo.jpg');
    }

    public function test_image_upload_rejects_a_non_image_file(): void
    {
        Storage::fake('public');
        Sanctum::actingAs($this->owner);

        $this->post('/api/owner/houses/'.$this->house->id.'/media', [
            'file' => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
        ])->assertStatus(422);

        $this->assertDatabaseCount('media', 0);
    }

    public function test_public_listing_exposes_house_and_room_images(): void
    {
        Room::factory()->forHouse($this->house)->create([
            'rent_amount' => 80000,
            'status' => 'available',
        ]);
        $room = Room::where('house_id', $this->house->id)->firstOrFail();

        Media::factory()->forHouse($this->house)->create(['sort_order' => 0]);
        Media::factory()->forHouse($this->house)->create(['sort_order' => 1]);
        Media::factory()->video()->forHouse($this->house)->create();
        Media::factory()->forRoom($room)->create();
        Media::factory()->video()->forRoom($room)->create();

        $response = $this->getJson('/api/listings/houses')->assertOk();

        $this->assertCount(2, $response->json('data.0.images'));
        $this->assertNotNull($response->json('data.0.video'));

        $detail = $this->getJson('/api/listings/houses/'.$this->house->id)->assertOk();
        $this->assertCount(2, $detail->json('data.house.images'));
        $this->assertNotNull($detail->json('data.house.video'));
        $this->assertCount(1, $detail->json('data.rooms.0.images'));
        $this->assertNotNull($detail->json('data.rooms.0.video'));
    }
}
