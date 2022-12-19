<?php

namespace Outl1ne\NovaMediaHub\Http\Controllers;

use Exception;
use Illuminate\Support\Arr;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller;
use Outl1ne\NovaMediaHub\MediaHub;
use Outl1ne\NovaMediaHub\MediaHandler\Support\Filesystem;

class MediaHubController extends Controller
{
    public function getCollections(Request $request)
    {
        $showAllCollections = config('nova-media-hub.show_all_collections_text');
        $defaultCollections = MediaHub::getDefaultCollections();

        $collections = MediaHub::getMediaModel()::select('collection_name')
            ->groupBy('collection_name')
            ->get()
            ->pluck('collection_name')
            ->merge($defaultCollections)
            ->merge($showAllCollections)
            ->unique()
            ->values()
            ->toArray();

        return response()->json($collections, 200);
    }

    public function getMedia()
    {
        $media = app(Pipeline::class)
            ->send(MediaHub::getQuery())->through([
                \Outl1ne\NovaMediaHub\Filters\Collection::class,
                \Outl1ne\NovaMediaHub\Filters\Search::class,
                \Outl1ne\NovaMediaHub\Filters\Sort::class,
            ])->thenReturn()->paginate(72);


        $newCollection = $media->getCollection()->map->formatForNova();
        $media->setCollection($newCollection);

        return response()->json($media, 200);
    }

    public function uploadMediaToCollection(Request $request)
    {
        $files = $request->allFiles()['files'] ?? [];
        $collectionName = $request->get('collectionName') ?? 'default';

        $exceptions = [];

        $uploadedMedia = [];
        foreach ($files as $file) {
            try {
                $uploadedMedia[] = MediaHub::fileHandler()
                    ->withFile($file)
                    ->withCollection($collectionName)
                    ->save();
            } catch (Exception $e) {
                $exceptions[] = $e;
                report($e);
            }
        }

        $uploadedMedia = collect($uploadedMedia);
        $coreResponse = [
            'media' => $uploadedMedia->map->formatForNova(),
            'hadExisting' => $uploadedMedia->where(fn ($m) => $m->wasExisting)->count() > 0,
            'success_count' => count($files) - count($exceptions),
        ];

        if (!empty($exceptions)) {
            return response()->json([
                ...$coreResponse,
                'errors' => Arr::map($exceptions, function ($e) {
                    $className = class_basename(get_class($e));
                    return "{$className}: {$e->getMessage()}";
                }),
            ], 400);
        }

        return response()->json($coreResponse, 200);
    }

    public function deleteMedia(Request $request)
    {
        $mediaId = $request->route('mediaId');
        if ($mediaId && $media = MediaHub::getQuery()->find($mediaId)) {
            /** @var Filesystem */
            $fileSystem = app()->make(Filesystem::class);
            $fileSystem->deleteFromMediaLibrary($media);
            $media->delete();
        }
        return response()->json('', 204);
    }

    public function moveMediaToCollection(Request $request, $mediaId)
    {
        $collectionName = $request->get('collection');
        if (!$collectionName) return response()->json(['error' => 'Collection name required.'], 400);

        $media = MediaHub::getQuery()->findOrFail($mediaId);

        $media->collection_name = $collectionName;
        $media->save();

        return response()->json($media, 200);
    }

    public function updateMediaData(Request $request, $mediaId)
    {
        $media = MediaHub::getQuery()->findOrFail($mediaId);
        $locales = MediaHub::getLocales();
        $fieldKeys = array_keys(MediaHub::getDataFields());

        // No translations, we hardcoded frontend to always send data as 'en'
        if (empty($locales)) {
            $mediaData = $media->data;
            foreach ($fieldKeys as $key) {
                $mediaData[$key] = $request->input("{$key}.en") ?? null;
            }
            $media->data = $mediaData;
        } else {
            $mediaData = $media->data;
            foreach ($fieldKeys as $key) {
                $mediaData[$key] = $request->input($key) ?? null;
            }
            $media->data = $mediaData;
        }

        $media->save();

        return response()->json($media, 200);
    }
}
