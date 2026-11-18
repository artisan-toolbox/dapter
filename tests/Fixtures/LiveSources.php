<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Tests\Fixtures;

use ArtisanToolbox\Dapter\Actions\ActionContext;
use ArtisanToolbox\Dapter\Attributes\Action;
use ArtisanToolbox\Dapter\Attributes\ActionAuthorize;
use ArtisanToolbox\Dapter\Broadcasting\ChannelReader;
use ArtisanToolbox\Dapter\Broadcasting\LiveSourceRegistration;
use ArtisanToolbox\Dapter\Broadcasting\PrivateChannel;
use ArtisanToolbox\Dapter\Broadcasting\PublicChannel;
use ArtisanToolbox\Dapter\Broadcasting\RestoredSource;
use ArtisanToolbox\Dapter\Broadcasting\ScopedAudience;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\ChannelContextResolver;
use ArtisanToolbox\Dapter\Contracts\DeliveryContextFactory;
use ArtisanToolbox\Dapter\Contracts\PublicRowProjection;
use ArtisanToolbox\Dapter\Contracts\RowRevisionProvider;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\Enums\ActionTransport;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\RecordTarget;
use ArtisanToolbox\Dapter\StoreOptions;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use ArtisanToolbox\Dapter\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Broadcasting\Broadcasters\Broadcaster;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Broadcasting\Factory;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Factories\Factory as EloquentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use stdClass;

final class LiveState
{
    /** @var array<int|string,array<string,mixed>> */
    public static array $principals = [];

    /** @var list<array<string,mixed>> */
    public static array $rows = [];

    /** @var list<array<string,mixed>> */
    public static array $events = [];

    public static int $reads = 0;

    public static int $actionCalls = 0;

    public static bool $allowed = true;

    /** @var list<int|string|null> */
    public static array $gateChecks = [];

    public static bool $producer = true;

    public static bool $mismatch = false;

    public static bool $malformed = false;

    public static ?StoreOptions $runtime = null;
}

final class LivePrincipal extends GenericUser
{
    public function tenant(): string
    {
        return $this->attributes['tenant'];
    }
}

final class LiveProvider implements UserProvider
{
    public function retrieveById($identifier): ?Authenticatable
    {
        return isset(LiveState::$principals[$identifier]) ? new LivePrincipal(['id' => $identifier, ...LiveState::$principals[$identifier]]) : null;
    }

    public function retrieveByToken($identifier, $token): ?Authenticatable
    {
        return null;
    }

    public function updateRememberToken(Authenticatable $user, $token): void {}

    /** @param array<string,mixed> $credentials */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        return null;
    }

    /** @param array<string,mixed> $credentials */
    public function validateCredentials(Authenticatable $user, array $credentials): bool
    {
        return false;
    }

    /** @param array<string,mixed> $credentials */
    public function rehashPasswordIfRequired(Authenticatable $user, array $credentials, bool $force = false): void {}
}

final class LiveAudience implements ChannelContextResolver
{
    public function __construct(private string $tenant) {}

    public function resolve(ChannelReader $reader): ?ScopedAudience
    {
        return LiveState::$allowed ? new ScopedAudience($this->tenant, 'reader-v1') : null;
    }
}

final class LiveResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return LiveState::$malformed ? ['bad' => new stdClass, 'number' => INF] : ['name' => $this->resource['name'], 'reader' => $request->user()?->getAuthIdentifier(), 'nested' => new LiveChildResource($this->resource)];
    }
}

final class LiveChildResource extends JsonResource
{
    /** @return array<string,mixed> */
    public function toArray(Request $request): array
    {
        return ['reader' => $request->user()?->getAuthIdentifier()];
    }
}

final class LiveRevision implements RowRevisionProvider
{
    public function revision(mixed $rawRow): string
    {
        return $rawRow['revision'];
    }
}

#[Unguarded]
final class LiveGateSubject extends Model
{
    /** @use HasFactory<EloquentFactory<static>> */
    use HasFactory;
}

final class LiveRows extends CollectionDataSource implements WithOptions
{
    public function __construct(public Request $request, private string $tenant) {}

    public static function declaration(): StoreOptions
    {
        return StoreOptions::make()->guard('live')->authorize('read-live', [new LiveGateSubject(['id' => 7, 'allowed' => true])])->useResource(LiveResource::class)->broadcastUpdatable(channel: new PrivateChannel('live.rows.{context}', LiveAudience::class), revisions: LiveRevision::class);
    }

    public function options(): StoreOptions
    {
        return LiveState::$runtime ?? self::declaration();
    }

    public function source(): array
    {
        LiveState::$reads++;

        return array_values(array_filter(LiveState::$rows, fn (array $row): bool => $row['tenant'] === $this->tenant));
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('inspect-live-record', target: new RecordTarget('id', ValueType::Integer))]
    private function inspect(Request $request, ActionContext $context): JsonResponse
    {
        LiveState::$actionCalls++;

        return response()->json(['actor' => $request->user()?->getAuthIdentifier(), 'tenant' => $this->tenant, 'record' => $context->record()['name']]);
    }
}

final class LiveContexts implements DeliveryContextFactory
{
    public function capture(Request $request): ?string
    {
        $user = ($request->getUserResolver())();

        return $user instanceof LivePrincipal ? $user->tenant() : null;
    }

    public function restore(string $key, ?ChannelReader $reader): ?RestoredSource
    {
        if ($reader === null || ! $reader->user instanceof LivePrincipal || $reader->user->tenant() !== $key) {
            return null;
        }
        $request = Request::create('/restored');
        $request->setUserResolver(static fn () => $reader->user);

        return new RestoredSource($request, new LiveRows($request, $key), LiveState::$mismatch ? null : new LiveAudience($key));
    }
}

final class PublicRows extends CollectionDataSource implements WithOptions
{
    public function __construct(private Request $sourceRequest) {}

    public static function declaration(): StoreOptions
    {
        return StoreOptions::make()->guard('live')->publicAccess()->broadcastUpdatable(channel: new PublicChannel('live.public', 'public-v1'));
    }

    public function options(): StoreOptions
    {
        return LiveState::$runtime ?? self::declaration();
    }

    public function source(): array
    {
        LiveState::$reads++;

        return [['id' => 1, 'name' => 'Public notebook']];
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('inspect-public-record', target: new RecordTarget('id', ValueType::Integer))]
    private function inspect(Request $request, ActionContext $context): JsonResponse
    {
        LiveState::$actionCalls++;

        return response()->json(['actor' => $request->user()?->getAuthIdentifier(), 'record' => $context->record()['name'], 'sourceReader' => $this->sourceRequest->user()?->getAuthIdentifier(), 'sourcePath' => $this->sourceRequest->path()]);
    }
}

final class PublicContexts implements DeliveryContextFactory
{
    public function capture(Request $request): string
    {
        return 'public';
    }

    public function restore(string $key, ?ChannelReader $reader): RestoredSource
    {
        $request = Request::create('/public');

        return new RestoredSource($request, new PublicRows($request));
    }
}

final class PublicProjection implements PublicRowProjection
{
    public function project(mixed $rawRow): mixed
    {
        return LiveState::$malformed ? ['nested' => new LiveChildResource($rawRow)] : ['name' => $rawRow['name']];
    }
}

final class RecordingBroadcaster extends Broadcaster
{
    public function auth($request): mixed
    {
        $request->merge(['channel_name' => preg_replace('/^private-/', '', $request->channel_name)]);

        return $this->verifyUserCanAccessChannel($request, $request->channel_name);
    }

    public function validAuthenticationResponse($request, $result): mixed
    {
        return $result;
    }

    /** @param list<string> $channels
     * @param array<string,mixed> $payload */
    public function broadcast(array $channels, $event, array $payload = []): void
    {
        LiveState::$events[] = ['channels' => $channels, 'event' => $event, 'payload' => $payload];
    }
}

final class LiveFixtureServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Auth::provider('live-fixture', static fn () => new LiveProvider);
        Gate::define('read-live', static function (Authenticatable $user, LiveGateSubject $subject): bool {
            LiveState::$gateChecks[] = $subject->getKey();

            return LiveState::$allowed && $subject->getAttribute('allowed') === true;
        });
        Gate::define('produce-live', static fn (Authenticatable $user): bool => LiveState::$producer);
        resolve(Factory::class)->extend('live-recording', fn () => new RecordingBroadcaster);
        $registry = resolve(SourceRegistry::class);
        $registry->registerLive('LiveRows', new LiveSourceRegistration(LiveRows::class, LiveRows::declaration(), LiveContexts::class, 'rows-v1'));
        $registry->registerLive('PublicRows', new LiveSourceRegistration(PublicRows::class, PublicRows::declaration(), PublicContexts::class, 'public-v1', PublicProjection::class, 'produce-live'));
        resolve(Factory::class)->routes(['middleware' => ['web']]);
    }
}

abstract class LiveTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.guards.live', ['driver' => 'session', 'provider' => 'live-fixture']);
        $app['config']->set('auth.providers.live-fixture', ['driver' => 'live-fixture']);
        $app['config']->set('broadcasting.default', 'live-recording');
        $app['config']->set('broadcasting.connections.live-recording', ['driver' => 'live-recording']);
        $app['config']->set('queue.default', 'sync');
    }

    /** @return list<class-string> */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), LiveFixtureServiceProvider::class];
    }
}
