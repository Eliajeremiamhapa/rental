<?php

namespace Database\Factories;

use App\Models\House;
use App\Models\Media;
use App\Models\Room;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Media>
 */
class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'type' => 'image',
            'path' => 'houses/'.fake()->uuid().'.jpg',
            'url' => null,
            'sort_order' => 0,
        ];
    }

    public function video(): static
    {
        return $this->state(fn () => [
            'type' => 'video',
            'path' => null,
            'url' => 'https://www.youtube.com/watch?v='.fake()->lexify('???????????'),
        ]);
    }

    public function forHouse(House $house): static
    {
        return $this->state(fn () => [
            'mediable_type' => House::class,
            'mediable_id' => $house->id,
        ]);
    }

    public function forRoom(Room $room): static
    {
        return $this->state(fn () => [
            'mediable_type' => Room::class,
            'mediable_id' => $room->id,
        ]);
    }
}
