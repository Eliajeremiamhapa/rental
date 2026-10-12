<?php

namespace App\Http\Controllers\API;

use App\Http\Concerns\AuthorizesOwnership;
use App\Http\Controllers\Controller;
use App\Models\House;
use App\Models\Media;
use App\Models\Room;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Owner-managed listing media (images + video) for houses and rooms.
 *
 * Uploads land on the public disk; videos may alternatively be an external
 * embed link so owners never have to upload a heavy file. Every action is
 * ownership-guarded through the polymorphic parent.
 */
class MediaController extends Controller
{
    use AuthorizesOwnership;

    public function storeForHouse(Request $request, House $house): JsonResponse
    {
        $this->ensureOwned($house);

        return $this->store($request, $house, 'houses');
    }

    public function storeForRoom(Request $request, Room $room): JsonResponse
    {
        $this->ensureOwned($room);

        return $this->store($request, $room, 'rooms');
    }

    public function update(Request $request, Media $media): JsonResponse
    {
        $this->ensureOwned($media->mediable);

        $validated = $request->validate([
            'sort_order' => 'required|integer|min:0',
        ]);

        $media->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Media order updated successfully.',
            'data' => $this->present($media->fresh()),
        ]);
    }

    public function destroy(Media $media): JsonResponse
    {
        $this->ensureOwned($media->mediable);

        if ($media->path) {
            Storage::disk('public')->delete($media->path);
        }

        $media->delete();

        return response()->json([
            'success' => true,
            'message' => 'Media deleted successfully.',
        ]);
    }

    private function store(Request $request, House|Room $mediable, string $folder): JsonResponse
    {
        $hasFile = $request->hasFile('file');
        $type = $request->input('type', $hasFile ? 'image' : 'video');

        abort_unless(in_array($type, ['image', 'video'], true), 422, 'Invalid media type.');

        if ($hasFile) {
            $request->validate($type === 'video'
                ? ['file' => 'file|mimetypes:video/mp4,video/webm,video/quicktime|max:20480']
                : ['file' => 'image|mimes:jpg,jpeg,png,webp|max:5120']);

            $attributes = [
                'type' => $type,
                'path' => $request->file('file')->store($folder, 'public'),
                'url' => null,
            ];
        } else {
            $validated = $request->validate([
                'url' => 'required|url|max:2048',
            ]);

            // External links are only supported for video embeds.
            $attributes = [
                'type' => 'video',
                'path' => null,
                'url' => $validated['url'],
            ];
        }

        $nextOrder = (int) $mediable->media()->max('sort_order') + 1;

        $media = $mediable->media()->create([
            ...$attributes,
            'sort_order' => $request->integer('sort_order', $nextOrder),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Media uploaded successfully.',
            'data' => $this->present($media),
        ], 201);
    }

    private function present(Media $media): array
    {
        return [
            'id' => $media->id,
            'type' => $media->type,
            'url' => $media->publicUrl(),
            'sort_order' => $media->sort_order,
        ];
    }
}
