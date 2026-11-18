<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Attributes\UseResource;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\RowSerializer;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use Illuminate\Auth\GenericUser;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class RecipientRowResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['name' => $this->resource['name'], 'reader' => $request->user()?->getAuthIdentifier()];
    }
}

class NestedRecipientResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['one' => new RecipientRowResource($this->resource), 'many' => RecipientRowResource::collection([$this->resource]), 'object' => (object) ['child' => new RecipientRowResource($this->resource)]];
    }
}

#[UseResource(NestedRecipientResource::class)]
class ResourceRows extends CollectionDataSource implements WithOptions
{
    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    public function source(): array
    {
        return [['id' => 1, 'name' => 'Notebook']];
    }
}

it('projects nested resources and collections through the public route for both protocols', function (int $version) {
    resolve(SourceRegistry::class)->register('ResourceRows', ResourceRows::class);
    $this->postJson('/dapter/data/ResourceRows', ['version' => $version])->assertOk()
        ->assertJsonPath('data.0.one.name', 'Notebook')
        ->assertJsonPath('data.0.many.0.name', 'Notebook')
        ->assertJsonPath('data.0.object.child.name', 'Notebook')
        ->assertJsonPath('data.0.one.reader', null);
})->with([1, 2]);

it('preserves ambient reader identity while nested projection uses an explicit request', function () {
    $ambient = Request::create('/ambient');
    app()->instance('request', $ambient);
    $resolver = $ambient->getUserResolver();
    $reader = clone $ambient;
    $user = new GenericUser(['id' => 29]);
    $reader->setUserResolver(static fn () => $user);
    $prepared = (new RowSerializer)->prepare([['id' => 1, 'name' => 'Notebook']], (new OptionsResolver)->resolve(new ResourceRows), $reader);
    expect($prepared->data[0]->one->reader)->toBe(29)
        ->and($prepared->data[0]->many[0]->reader)->toBe(29)
        ->and($prepared->data[0]->object->child->reader)->toBe(29)
        ->and(request())->toBe($ambient)->and($ambient->getUserResolver())->toBe($resolver);
});

it('validates resource declarations and duplicate declarations before reading', function () {
    expect(fn () => StoreOptions::make()->useResource(RecipientRowResource::class)->useResource(RecipientRowResource::class))->toThrow(DapterException::class);
    $source = new class extends CollectionDataSource implements WithOptions
    {
        public function options(): StoreOptions
        {
            return (new ReflectionMethod(StoreOptions::class, 'useResource'))->invoke(StoreOptions::make(), stdClass::class);
        }

        public function source(): array
        {
            throw new LogicException('The source must not be read.');
        }
    };
    expect(fn () => (new OptionsResolver)->resolve($source))->toThrow(DapterException::class);
});

class OpaqueResourceRows extends ResourceRows
{
    public function options(): StoreOptions
    {
        return parent::options()->globalOrderable('reverse', 'Reverse', static fn (Collection $rows): Collection => $rows->reverse());
    }

    public function source(): array
    {
        return [['id' => 1, 'name' => 'Notebook', 'onSave' => static fn (): bool => true]];
    }
}

it('projects opaque internal row values without serializing unused handler snapshots', function (int $version): void {
    resolve(SourceRegistry::class)->register('OpaqueResourceRows', OpaqueResourceRows::class);
    $this->postJson('/dapter/data/OpaqueResourceRows', ['version' => $version])->assertOk()
        ->assertJsonPath('data.0.one', ['name' => 'Notebook', 'reader' => null])
        ->assertJsonPath('data.0.many.0', ['name' => 'Notebook', 'reader' => null])
        ->assertJsonMissingPath('data.0.onSave');
})->with([1, 2]);
