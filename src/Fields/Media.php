<?php

namespace Ebess\AdvancedNovaMediaLibrary\Fields;

// @TODO Rule contract is deprecated since laravel/framework v10.0, replace with ValidationRule once min version is 10.
use Illuminate\Contracts\Validation\Rule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Laravel\Nova\Fields\Field;
use Laravel\Nova\Http\Requests\NovaRequest;
use Spatie\MediaLibrary\MediaCollections\FileAdder;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class Media extends Field
{
    use HandlesConversionsTrait, HandlesCustomPropertiesTrait, HandlesExistingMediaTrait;

    public $component = 'advanced-media-library-field';

    protected $setFileNameCallback;

    protected $setNameCallback;

    protected $serializeMediaCallback;

    protected $responsive = false;

    protected $collectionMediaRules = [];

    protected $singleMediaRules = [];

    protected $customHeaders = [];

    protected $secureUntil;

    protected $defaultValidatorRules = [];

    public $meta = ['type' => 'media'];

    public function serializeMediaUsing(callable $serializeMediaUsing): self
    {
        $this->serializeMediaCallback = $serializeMediaUsing;

        return $this;
    }

    public function fullSize(): self
    {
        return $this->withMeta(['fullSize' => true]);
    }

    public function rules($rules): self
    {
        $this->collectionMediaRules = ($rules instanceof Rule || is_string($rules)) ? func_get_args() : $rules;

        return $this;
    }

    public function singleMediaRules($rules): self
    {
        $this->singleMediaRules = ($rules instanceof Rule || is_string($rules)) ? func_get_args() : $rules;

        return $this;
    }

    public function customHeaders(array $headers): self
    {
        $this->customHeaders = $headers;

        return $this;
    }

    /**
     * Set the responsive mode, which enables the creation of responsive images on upload
     *
     * @param  bool  $responsive
     * @return $this
     */
    public function withResponsiveImages($responsive = true)
    {
        $this->responsive = $responsive;

        return $this;
    }

    /**
     * Set a filename callable callback
     *
     * @param  callable  $callback
     * @return $this
     */
    public function setFileName($callback)
    {
        $this->setFileNameCallback = $callback;

        return $this;
    }

    /**
     * Set a name callable callback
     *
     * @param  callable  $callback
     * @return $this
     */
    public function setName($callback)
    {
        $this->setNameCallback = $callback;

        return $this;
    }

    /**
     * Set the maximum accepted file size for the frontend in kBs
     *
     *
     * @return $this
     */
    public function setMaxFileSize(int $maxSize)
    {
        return $this->withMeta(['maxFileSize' => $maxSize]);
    }

    /**
     * Validate the file's type on the frontend side
     * Example values for the array: 'image', 'video', 'image/jpeg'
     *
     *
     * @return $this
     */
    public function setAllowedFileTypes(array $types)
    {
        return $this->withMeta(['allowedFileTypes' => $types]);
    }

    /**
     * Set the expiry time for temporary urls.
     *
     *
     * @return $this
     */
    public function temporary(Carbon $until)
    {
        $this->secureUntil = $until;

        return $this;
    }

    public function uploadsToVapor(bool $uploadsToVapor = true): self
    {
        return $this->withMeta(['uploadsToVapor' => $uploadsToVapor]);
    }

    protected function fillAttributeFromRequest(NovaRequest $request, $requestAttribute, $model, $attribute)
    {
        $key = str_replace($attribute, '__media__.'.$attribute, $requestAttribute);
        $data = $request[$key] ?? [];

        if ($attribute === 'ComputedField') {
            $attribute = call_user_func($this->computedCallback, $model);
        }

        collect($data)
            ->filter(function ($value) {
                return $value instanceof UploadedFile;
            })
            ->each(function ($media) use ($request, $requestAttribute) {
                $requestToValidateSingleMedia = array_merge($request->toArray(), [
                    $requestAttribute => $media,
                ]);

                Validator::make($requestToValidateSingleMedia, [
                    $requestAttribute => array_merge($this->defaultValidatorRules, (array) $this->singleMediaRules),
                ])->validate();
            });

        $requestToValidateCollectionMedia = array_merge($request->toArray(), [
            $requestAttribute => $data,
        ]);

        Validator::make($requestToValidateCollectionMedia, [$requestAttribute => $this->collectionMediaRules])
            ->validate();

        return function () use ($request, $data, $attribute, $model, $requestAttribute) {
            $this->handleMedia($request, $model, $attribute, $data, $requestAttribute);

            // fill custom properties for existing media
            $this->fillCustomPropertiesFromRequest($request, $requestAttribute, $model, $attribute);
        };
    }

    protected function handleMedia(NovaRequest $request, $model, $attribute, $data, $requestAttribute)
    {
        // ok by default $attribute is the collectionname, but this does not work with our central media collection
        // so our fields will have 2 attributes required:
        // - the create one will be the global collection
        // - and we add a method to give model_collection_name to the field

        $collectionName = $this->meta['collectionName'] ?? $attribute;
        $modelCollectionName = $this->meta['modelCollectionName'];

        if($modelCollectionName) {
            $this->removeDeletedMedia($data, $model->getMediaByModelCollectionName($modelCollectionName), $model);
        }else {
            $this->removeDeletedMedia($data, $model->getMedia($collectionName), $model);
        }

//         ok this will just add the media without saving anything really in a way
        $this->addNewMedia($request, $data, $model, $collectionName, $requestAttribute, $modelCollectionName);

        // this is where the magic happens
        if ($modelCollectionName) {
            $this->addExistingMedia($request, $data, $model, $collectionName, $model->getMediaByModelCollectionName($modelCollectionName), $requestAttribute);
        } else {
            $this->addExistingMedia($request, $data, $model, $collectionName, $model->getMedia($collectionName), $requestAttribute);
        }

        //$this->setOrder($remainingIds->union($newIds)->union($existingIds)->sortKeys()->all());
    }

    private function setOrder($ids)
    {
        $mediaClass = config('media-library.media_model');
        $mediaClass::setNewOrder($ids);
    }

    private function addNewMedia(NovaRequest $request, $data, Model $model, string $collection, string $requestAttribute, string $modelCollectionName): Collection
    {
        return collect($data)
            ->filter(function ($value) {
                // New files will come in as UploadedFile objects,
                // whereas Vapor-uploaded files will come in as arrays.
                return $value instanceof UploadedFile || is_array($value);
            })->map(function ($file, int $index) use ($request, $model, $collection, $requestAttribute, $modelCollectionName) {
                if ($file instanceof UploadedFile) {
                    $media = $model->addMedia($file)->withCustomProperties($this->customProperties);

                    $fileName = $file->getClientOriginalName();
                    $fileExtension = $file->getClientOriginalExtension();

                } else {
                    $media = $this->makeMediaFromVaporUpload($file, $model);

                    $fileName = $file['file_name'];
                    $fileExtension = pathinfo($file['file_name'], PATHINFO_EXTENSION);
                }

                if ($this->responsive) {
                    $media->withResponsiveImages();
                }

                if (! empty($this->customHeaders)) {
                    $media->addCustomHeaders($this->customHeaders);
                }

                if (is_callable($this->setFileNameCallback)) {
                    $media->setFileName(
                        call_user_func($this->setFileNameCallback, $fileName, $fileExtension, $model)
                    );
                }

                if (is_callable($this->setNameCallback)) {
                    $media->setName(
                        call_user_func($this->setNameCallback, $fileName, $model)
                    );
                }

                $media->setModelCollectionName($modelCollectionName);
                $media = $media->toMediaCollection($collection);

                // fill custom properties for recently created media
                $this->fillMediaCustomPropertiesFromRequest($request, $media, $index, $collection, $requestAttribute);

                return $media->getKey();
            });
    }

    private function removeDeletedMedia($data, Collection $medias, Model $model): void
    {
        $modelCollectionName = $this->meta['modelCollectionName'];

        $wantedIds = collect($data)->filter(function ($value) {
            // New files will come in as UploadedFile objects,
            // whereas Vapor-uploaded files will come in as arrays.
            return !$value instanceof UploadedFile
                && !is_array($value);
        });
        $existingIds = $model->getMediaByModelCollectionName($modelCollectionName)->pluck('id');

        //determine which media we need to detach, if any
        $idsToDetach = $existingIds->diff($wantedIds);
        $model->media()->detach($idsToDetach);
    }

    public function resolve($resource, $attribute = null)
    {
        $collectionName = $this->meta['collectionName'] ?? $attribute ?? $this->attribute;
        $modelCollectionName = $this->meta['modelCollectionName'];

        if ($collectionName === 'ComputedField') {
            $collectionName = call_user_func($this->computedCallback, $resource);
        }

        if ($modelCollectionName) {
            $medias = $resource->getMediaByModelCollectionName($modelCollectionName);
        } else {
            $medias = $resource->getMedia($collectionName);
        }

        $this->value = $medias->map(function (\Spatie\MediaLibrary\MediaCollections\Models\Media $media) {
            return array_merge($this->serializeMedia($media), [
                'uuid' => $media->uuid,
                '__media_urls__' => $this->getMediaUrls($media),
            ]);
        })->values();
        //        if ($collectionName) {
        //            $this->checkCollectionIsMultiple($resource, $collectionName);
        //        }
    }

    /**
     * Get the urls for the given media.
     *
     * @return array
     */
    public function getMediaUrls($media)
    {
        if (isset($this->secureUntil) && $this->secureUntil instanceof Carbon) {
            return $this->getTemporaryConversionUrls($media);
        }

        return $this->getConversionUrls($media);
    }

    //    protected function checkCollectionIsMultiple(Model $resource, string $collectionName)
    //    {
    //        $resource->registerMediaCollections();
    //        $isSingle = collect($resource->mediaCollections)
    //            ->where('name', $collectionName)
    //            ->first()
    //            ->singleFile ?? false;
    //
    //        $this->withMeta(['multiple' => ! $isSingle]);
    //    }

    public function serializeMedia(Model $media): array
    {
        if ($this->serializeMediaCallback) {
            return call_user_func($this->serializeMediaCallback, $media);
        }

        return $media->toArray();
    }

    /**
     * field recognizes single/multi file media by itself -> reverted this change, we need this
     */
    public function multiple(): self
    {
        return $this->withMeta(['multiple' => true]);
    }

    public function single(): self
    {
        return $this->withMeta(['multiple' => false]);
    }

    public function modelCollectionName(string $modelCollectionName): self
    {
        return $this->withMeta(compact('modelCollectionName'));
    }

    public function collectionName(string $collectionName): self
    {
        return $this->withMeta(compact('collectionName'));
    }

    /**
     * @deprecated
     * @see conversionOnIndexView
     */
    public function thumbnail(string $conversionOnIndexView): self
    {
        return $this->withMeta(compact('conversionOnIndexView'));
    }

    /**
     * @deprecated
     * @see conversionOnPreview
     */
    public function conversion(string $conversionOnPreview): self
    {
        return $this->withMeta(compact('conversionOnPreview'));
    }

    /**
     * @deprecated
     * @see conversionOnDetailView
     */
    public function conversionOnView(string $conversionOnDetailView): self
    {
        return $this->withMeta(compact('conversionOnDetailView'));
    }

    /**
     * This creates a Media object from a previously, client-side, uploaded file.
     * The file is uploaded using a pre-signed S3 URL, via Vapor.store.
     * This method will use addMediaFromUrl(), passing it the
     * temporary location of the file.
     *
     * @throws \Spatie\MediaLibrary\MediaCollections\Exceptions\FileCannotBeAdded
     */
    private function makeMediaFromVaporUpload(array $file, Model $model): FileAdder
    {
        $disk = config('filesystems.default');

        $disk = config('filesystems.disks.'.$disk.'driver') === 's3' ? $disk : 's3';

        $url = Storage::disk($disk)->temporaryUrl($file['key'], Carbon::now()->addHour());

        return $model->addMediaFromUrl($url)
            ->usingFilename($file['file_name'])
            ->withCustomProperties($this->customProperties);
    }
}
