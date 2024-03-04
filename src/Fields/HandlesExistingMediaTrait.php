<?php

namespace Ebess\AdvancedNovaMediaLibrary\Fields;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\MediaLibrary\MediaCollections\Filesystem;
use Spatie\TemporaryDirectory\TemporaryDirectory;

/**
 * @mixin Media
 */
trait HandlesExistingMediaTrait
{
    public function enableExistingMedia(): self
    {
        return $this->withMeta(['existingMedia' => (bool) config('nova-media-library.enable-existing-media')]);
    }


    private function addExistingMedia(NovaRequest $request, $data, Model $model, string $collection, Collection $medias, string $requestAttribute): Collection
    {
        // data will be a mixed type, it will be the id of the media if we are linking existing media
        // or it will be an instance of UploadedFile if we are uploading a new file
        // we will have to check the type of each item in  $data to know what to do
        $ids = collect($data)
            ->filter(function ($value) {
                // not an instance of UploadedFile
                return !($value instanceof UploadedFile);
            });

        ray($ids);
        $model->media()->syncWithoutDetaching($ids);
        return $model->media->pluck('id');
    }
    private function addExistingMedia_old(NovaRequest $request, $data, Model $model, string $collection, Collection $medias, string $requestAttribute): Collection
    {
        // this field will have a value if we are uploading a new file, this will somehow be the id of the media
//        $addedMediaIds = $medias->pluck('id')->toArray();
//        ray($addedMediaIds)->green()->label('addedMediaIds');

        // if we link existing files this will be an array of ids, if we upload a new file this will be the file data
        ray($data)->green()->label('data');
//        ray($requestAttribute)->green()->label('requestAttribute');
//        ray($collection)->green()->label('collection');
//        ray($model)->green()->label('model');
//        ray($request)->green()->label('request');

        // let's check if $data is of type UploadedFile
        if ($data instanceof UploadedFile) {
            // if it is, we are uploading a new file
            // we have to add it to the media library and attach it to the model

            // however I am not sure WHY we are getting here given we are uploading a new image

            // this is sort off the old behaviour of this package
//            $addedMediaIds = $medias->pluck('id')->toArray();
//            return collect($data)
//                ->filter(function ($value) use ($addedMediaIds) {
//                    // New files will come in as UploadedFile objects,
//                    // whereas Vapor-uploaded files will come in as arrays.
//                    return (! ($value instanceof UploadedFile)) && (! (is_array($value))) && ! (in_array($value, $addedMediaIds));
//                })->map(function ($model_id, int $index) use ($request, $model, $collection, $requestAttribute) {
//                    $mediaClass = config('media-library.media_model');
//                    $existingMedia = $mediaClass::find($model_id);
//
//                    // Mimic copy behaviour
//                    // See Spatie\MediaLibrary\Models\Media->copy()
//                    $temporaryDirectory = TemporaryDirectory::create();
//                    $temporaryFile = $temporaryDirectory->path($existingMedia->file_name);
//                    app(Filesystem::class)->copyFromMediaLibrary($existingMedia, $temporaryFile);
//                    $media = $model->addMedia($temporaryFile)->withCustomProperties($this->customProperties);
//
//                    if ($this->responsive) {
//                        $media->withResponsiveImages();
//                    }
//
//                    if (! empty($this->customHeaders)) {
//                        $media->addCustomHeaders($this->customHeaders);
//                    }
//
//                    $media = $media->toMediaCollection($collection);
//
//                    // fill custom properties for recently created media
//                    $this->fillMediaCustomPropertiesFromRequest($request, $media, $index, $collection, $requestAttribute);
//
//                    // Delete our temp collection
//                    $temporaryDirectory->delete();
//
//                    return $media->getKey();
//                });

            return $model->media->pluck('id');
        } else {
            // if it is not, we are linking existing media
            // we have to attach the existing media to the model
            $model->media()->syncWithoutDetaching($data);
            return $model->media->pluck('id');
        }




    }
}
