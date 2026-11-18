<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Attributes\BroadcastUpdatable;
use ArtisanToolbox\Dapter\Attributes\UseResource;
use ArtisanToolbox\Dapter\Broadcasting\ChannelReader;
use ArtisanToolbox\Dapter\Broadcasting\GlobalAudience;
use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\PublicChannel;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\ChannelContextResolver;
use ArtisanToolbox\Dapter\Contracts\RowIdentityProvider;
use ArtisanToolbox\Dapter\Contracts\RowRevisionProvider;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\Drivers\SqlIdentitySelector;
use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Http\RowSerializer;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\StoreOptions;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;

final class OptionAudience implements ChannelContextResolver
{
    public function resolve(ChannelReader $reader): GlobalAudience
    {
        return new GlobalAudience('reader-v1');
    }
}
final class OtherOptionAudience implements ChannelContextResolver
{
    public function resolve(ChannelReader $reader): GlobalAudience
    {
        return new GlobalAudience('reader-v2');
    }
}
class OptionRowResource extends JsonResource {}
final class OtherOptionRowResource extends JsonResource {}
final class OptionRevision implements RowRevisionProvider
{
    public function revision(mixed $rawRow): string
    {
        return '0';
    }
}
final class OptionIdentity implements RowIdentityProvider
{
    public function identify(mixed $rawRow): int|string
    {
        return $rawRow['key'];
    }

    public function selector(): ?SqlIdentitySelector
    {
        return null;
    }

    public function lookup(int|string $publicIdentifier): int|string
    {
        return $publicIdentifier;
    }
}
class ProjectionOptionRows extends CollectionDataSource implements WithOptions
{
    public static ?StoreOptions $settings = null;

    public function options(): StoreOptions
    {
        return self::$settings ?? StoreOptions::make();
    }

    public function source(): array
    {
        return [];
    }
}
#[UseResource(OptionRowResource::class)]
#[BroadcastUpdatable(identifierField: 'key', channel: new PrivateChannel('parent.{context}', OptionAudience::class), revisions: OptionRevision::class)]
class ParentProjectionRows extends ProjectionOptionRows {}
#[UseResource(OtherOptionRowResource::class)]
#[BroadcastUpdatable(channel: new PrivateChannel('child.{context}', OptionAudience::class), identity: OptionIdentity::class)]
final class ChildProjectionRows extends ParentProjectionRows {}
#[UseResource(OptionRowResource::class)]
#[UseResource(OtherOptionRowResource::class)]
final class DuplicateProjectionRows extends ProjectionOptionRows {}
#[Hidden(['uuid'])]
#[Unguarded]
#[WithoutTimestamps]
final class HiddenProjectionModel extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;

    protected $primaryKey = 'uuid';
}

afterEach(function (): void {
    ProjectionOptionRows::$settings = null;
});

it('replaces atomic inherited declarations with complete child and fluent declarations', function () {
    $options = (new OptionsResolver)->resolve(new ChildProjectionRows);
    expect($options->resource)->toBe(OtherOptionRowResource::class)
        ->and($options->broadcasting->identity)->toBe(OptionIdentity::class)
        ->and($options->broadcasting->identifierField)->toBeNull()->and($options->broadcasting->revisions)->toBeNull();
    ProjectionOptionRows::$settings = StoreOptions::make()->useResource(OptionRowResource::class)->broadcastUpdatable(identifierField: 'explicit', channel: new PrivateChannel('explicit.{context}', OptionAudience::class));
    $options = (new OptionsResolver)->resolve(new ChildProjectionRows);
    expect($options->resource)->toBe(OptionRowResource::class)->and($options->broadcasting->identifierField)->toBe('explicit')
        ->and($options->broadcasting->identity)->toBeNull()->and($options->broadcasting->revisions)->toBeNull();
});

it('captures shorthand context once while ordinary defaults remain disabled and empty factories remain empty', function () {
    config()->set('dapter.broadcasting.context', OptionAudience::class);
    $declaration = StoreOptions::make()->broadcastUpdatable();
    config()->set('dapter.broadcasting.context', OtherOptionAudience::class);
    ProjectionOptionRows::$settings = $declaration;
    $options = (new OptionsResolver)->resolve(new ProjectionOptionRows);
    $channel = $options->broadcasting->channel;

    throw_unless($channel instanceof PrivateChannel, LogicException::class, 'The shorthand channel must be private.');
    expect($channel->context)->toBe(OptionAudience::class)
        ->and($channel->pattern)->toBe('dapter.web.{source}.{context}');
    ProjectionOptionRows::$settings = StoreOptions::make();
    expect((new OptionsResolver)->resolve(new ProjectionOptionRows)->broadcasting)->toBeNull()
        ->and(StoreOptions::makeWithoutDefaults()->baselineValues())->toBe([])->and(StoreOptions::makeWithoutDefaults()->explicitValues())->toBe([]);
});

it('rejects duplicate malformed and unsupported projection declarations before any read', function () {
    expect(fn () => (new OptionsResolver)->resolve(new DuplicateProjectionRows))->toThrow(DapterException::class);
    foreach ([StoreOptions::make()->broadcastUpdatable(), StoreOptions::make()->broadcastUpdatable(identifierField: 'table.key', channel: new PrivateChannel('rows.{context}', OptionAudience::class)), StoreOptions::make()->broadcastUpdatable(identifierField: 'key', identity: OptionIdentity::class), StoreOptions::make()->useResource(ResourceCollection::class), StoreOptions::make()->useResource(OptionRowResource::class)->broadcastUpdatable(channel: new PublicChannel('rows.public', 'public-v1'))] as $declaration) {
        ProjectionOptionRows::$settings = $declaration;
        expect(fn () => (new OptionsResolver)->resolve(new ProjectionOptionRows))->toThrow(DapterException::class);
    }
    expect(fn () => StoreOptions::make()->broadcastUpdatable()->broadcastUpdatable())->toThrow(DapterException::class);
});

it('protects inferred hidden model keys while explicit selection discloses only metadata', function () {
    $row = new HiddenProjectionModel(['uuid' => 7, 'name' => 'Notebook']);
    ProjectionOptionRows::$settings = StoreOptions::make()->broadcastUpdatable(channel: new PrivateChannel('rows.{context}', OptionAudience::class));
    $options = (new OptionsResolver)->resolve(new ProjectionOptionRows);
    expect(fn () => (new RowSerializer)->prepare([$row], $options, Request::create('/reader')))->toThrow(DapterException::class);
    ProjectionOptionRows::$settings = StoreOptions::make()->broadcastUpdatable(identifierField: 'uuid', channel: new PrivateChannel('rows.{context}', OptionAudience::class));
    $options = (new OptionsResolver)->resolve(new ProjectionOptionRows);
    $prepared = (new RowSerializer)->prepare([$row], $options, Request::create('/reader'));
    expect($prepared->records)->toBe(['mode' => 'identified', 'rows' => [['id' => 7]]])->and(get_object_vars($prepared->data[0]))->toBe(['name' => 'Notebook']);
});
