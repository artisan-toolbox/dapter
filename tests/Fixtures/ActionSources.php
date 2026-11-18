<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Tests\Fixtures;

use ArtisanToolbox\Dapter\Actions\ActionContext;
use ArtisanToolbox\Dapter\Attributes\Action;
use ArtisanToolbox\Dapter\Attributes\ActionAuthorize;
use ArtisanToolbox\Dapter\Attributes\GlobalFilter;
use ArtisanToolbox\Dapter\CollectionDataSource;
use ArtisanToolbox\Dapter\Contracts\WithOptions;
use ArtisanToolbox\Dapter\EloquentDataSource;
use ArtisanToolbox\Dapter\Enums\ActionMethod;
use ArtisanToolbox\Dapter\Enums\ActionTransport;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\RecordTarget;
use ArtisanToolbox\Dapter\StoreOptions;
use Closure;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Attributes\Unguarded;
use Illuminate\Database\Eloquent\Attributes\WithoutIncrementing;
use Illuminate\Database\Eloquent\Attributes\WithoutTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class ActionState
{
    public static int $owners = 0;

    public static int $calls = 0;

    public static int $forms = 0;

    public static bool $authorize = true;
}

final class SaveActionRequest extends FormRequest
{
    public function authorize(): bool
    {
        ActionState::$forms++;

        return ActionState::$authorize;
    }

    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'min:2']];
    }
}

class ActionParentSource extends CollectionDataSource implements WithOptions
{
    public function source(): array
    {
        return [['id' => 1, 'name' => 'Lin'], ['id' => 2, 'name' => 'Ada']];
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess()->withActions(ExternalActions::class);
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('parent-save')]
    private function inherited(): JsonResponse
    {
        ActionState::$calls++;

        return response()->json(['parent' => true]);
    }
}

final class ActionTestSource extends ActionParentSource
{
    #[Action(transports: [ActionTransport::Visit, ActionTransport::Http])]
    #[ActionAuthorize('save', target: new RecordTarget('id', ValueType::Integer))]
    private function save(SaveActionRequest $request, ActionContext $context): JsonResponse|RedirectResponse
    {
        ActionState::$calls++;

        return $request->expectsJson() ? response()->json(['saved' => $context->record()['id'], 'name' => $request->validated('name')]) : redirect('/saved');
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('save')]
    private function mutateThenFail(): JsonResponse
    {
        ActionState::$calls++;

        throw ValidationException::withMessages(['name' => ['The write already ran.']]);
    }

    #[Action(transports: [ActionTransport::Http])]
    private function formOnly(SaveActionRequest $request): JsonResponse
    {
        ActionState::$calls++;

        return response()->json(['saved' => true]);
    }
}

trait ExternalActionTrait
{
    #[Action(transports: [ActionTransport::Http], method: ActionMethod::Patch)]
    #[ActionAuthorize('external')]
    public function traitSave(SaveActionRequest $request): JsonResponse
    {
        ActionState::$calls++;

        $this->helper();

        return response()->json(['external' => true]);
    }
}
class ExternalActionParent
{
    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('external')]
    public function excluded(): JsonResponse
    {
        throw new RuntimeException;
    }
}
final class ExternalActions extends ExternalActionParent
{
    use ExternalActionTrait;

    public function __construct()
    {
        ActionState::$owners++;
    }

    private function helper(): void {}
}

final class StringActionRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return ['record_id' => ['present', 'string'], 'name' => ['string'], 'note' => ['nullable', 'string'], 'attachment' => ['file']];
    }
}

final class StringActionSource extends CollectionDataSource implements WithOptions
{
    public static int $sources = 0;

    public static string $key = 'a/b?#%ação';

    public function source(): array
    {
        self::$sources++;

        return [['id' => self::$key, 'name' => 'Lin']];
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('string-save', arguments: ['trusted'], target: new RecordTarget('record_id'))]
    private function save(StringActionRequest $request, ActionContext $context): JsonResponse
    {
        ActionState::$calls++;
        $attachment = $request->validated('attachment');

        return response()->json([
            'saved' => $context->record()['id'],
            'fields' => $request->safe()->only(['record_id', 'name', 'note']),
            'upload' => $attachment instanceof UploadedFile ? $attachment->getContent() : null,
        ]);
    }
}
final class GuardActionSource extends CollectionDataSource implements WithOptions
{
    public function source(): array
    {
        return [['id' => 1]];
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->guard('alternate')->authorize('source-guard');
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('guard-save')]
    private function save(Request $request): JsonResponse
    {
        ActionState::$calls++;

        return response()->json(['principal' => $request->user()?->getAuthIdentifier()]);
    }
}
final class RulesOnlyActionRequest extends FormRequest
{
    /** @return array<string,list<string>> */
    public function rules(): array
    {
        return [];
    }
}
final class InvalidRulesOnlyActionSource extends CollectionDataSource implements WithOptions
{
    public function source(): array
    {
        return [];
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    #[Action]
    private function save(RulesOnlyActionRequest $request): JsonResponse
    {
        return response()->json([]);
    }
}
final class DuplicateActionSource extends ActionParentSource
{
    #[Action(id: 'INHERITED')]
    #[ActionAuthorize('save')]
    private function another(): JsonResponse
    {
        return response()->json([]);
    }
}
final class DualActionSource extends CollectionDataSource implements WithOptions
{
    public function source(): array
    {
        return [];
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    /** @param Collection<array-key,mixed> $rows
     * @return Collection<array-key,mixed>
     */
    #[Action]
    #[GlobalFilter(values: [1])]
    #[ActionAuthorize('save')]
    private function save(Collection $rows, int $value): Collection
    {
        return $rows;
    }
}

#[Unguarded]
#[Table(name: 'dapter_action_records', key: 'uuid', keyType: 'string')]
#[WithoutIncrementing]
#[WithoutTimestamps]
final class ActionUuidRecord extends Model
{
    /** @use HasFactory<Factory<static>> */
    use HasFactory;
}
/** @extends EloquentDataSource<ActionUuidRecord> */
class UuidActionSource extends EloquentDataSource implements WithOptions
{
    public function source(): Builder
    {
        return ActionUuidRecord::query()->where('tenant', 1);
    }

    public function options(): StoreOptions
    {
        return StoreOptions::make()->publicAccess();
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('uuid-save', target: new RecordTarget('record_id'))]
    private function save(ActionContext $context): JsonResponse
    {
        ActionState::$calls++;

        return response()->json(['saved' => $context->record()->getKey()]);
    }
}

final class DisjunctiveUuidActionSource extends UuidActionSource
{
    public function source(): Builder
    {
        return parent::source()->orWhere('tenant', 3)
            ->withGlobalScope('fourth-tenant', static fn (Builder $query): Builder => $query->orWhere('tenant', 4));
    }
}

final class ProjectedUuidActionSource extends UuidActionSource
{
    public function source(): Builder
    {
        return ActionUuidRecord::query()
            ->join('dapter_action_records as visible', 'dapter_action_records.uuid', '=', 'visible.uuid')
            ->where('visible.tenant', 1)
            ->select('dapter_action_records.uuid', 'dapter_action_records.name as public_name')
            ->distinct();
    }

    public function options(): StoreOptions
    {
        return parent::options()->actionAuthorize('save', 'uuid-save', target: new RecordTarget('record_id', target: 'dapter_action_records.name', output: 'public_name'));
    }
}

final class BoundedUuidActionSource extends UuidActionSource
{
    public static Closure $constrain;

    public function source(): Builder
    {
        return (self::$constrain)(parent::source()->orderBy('uuid'));
    }

    #[Action(transports: [ActionTransport::Http])]
    #[ActionAuthorize('source-summary')]
    private function summary(ActionContext $context): JsonResponse
    {
        return response()->json(['ids' => $context->source()->pluck('uuid')->all()]);
    }
}
