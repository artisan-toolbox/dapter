<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Actions\ActionRegistry;
use ArtisanToolbox\Dapter\Broadcasting\RecipientContexts;
use ArtisanToolbox\Dapter\Contracts\IdentityLookupDriver;
use ArtisanToolbox\Dapter\Drivers\CursorPage;
use ArtisanToolbox\Dapter\Drivers\DriverFactory;
use ArtisanToolbox\Dapter\Drivers\NegotiatedRead;
use ArtisanToolbox\Dapter\Drivers\OrderTerm;
use ArtisanToolbox\Dapter\Drivers\RawPage;
use ArtisanToolbox\Dapter\Drivers\SimplePage;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Enums\OrderKind;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Paging\CursorAccess;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use ArtisanToolbox\Dapter\Support\SourceResolver;
use ArtisanToolbox\Dapter\Support\SourceVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class DataController
{
    public function __invoke(Request $request, string $source): JsonResponse
    {
        $resolver = $request->getUserResolver();
        $version = 1;

        try {
            $intent = ReadIntent::fromJson($request->getContent());
            $version = $intent->version();
            $live = resolve(SourceRegistry::class)->live($source) === null ? null : resolve(RecipientContexts::class)->listing($source, $request);
            $instance = $live?->restored->source ?? (new SourceResolver)->resolve($source);
            $options = $live === null ? (new OptionsResolver)->resolve($instance) : $live->options;
            $user = $live === null ? resolve(Factory::class)->guard($options->guard)->user() : $live->restored->request->user();

            throw_if($options->access === AccessMode::Required && $user === null, DapterException::class, ErrorKind::Access, ErrorCode::AuthenticationRequired, 401);
            $request->setUserResolver(static fn (?string $guard = null) => $user);

            if ($live === null && $options->authorization !== null) {
                try {
                    resolve(Gate::class)->forUser($user)->authorize($options->authorization->ability, $options->authorization->arguments);
                } catch (AuthorizationException $exception) {
                    $status = $exception->status();
                    $status = is_int($status) && $status >= 400 && $status <= 599 ? $status : 403;

                    throw new DapterException(ErrorKind::Access, $status === 404 ? ErrorCode::SourceNotFound : ErrorCode::AccessDenied, $status);
                }
            }

            if ($options->broadcasting !== null && $live === null) {
                throw DapterException::configuration();
            }
            $browserVersion = $version === 2 && $request->header('X-Dapter-Browser-State') === '1'
                ? (new SourceVersion)->forSource($instance)
                : null;

            if ($browserVersion !== null && $request->hasHeader('X-Dapter-State-Version') && ! hash_equals($browserVersion, (string) $request->header('X-Dapter-State-Version'))) {
                $intent = ReadIntent::fromJson('{"version":2}');
            }
            $paging = $intent->paging($options);
            $selection = $intent->selection($options);
            $cursorAccess = $paging instanceof CursorRequest ? CursorAccess::forSource($source, $instance, $options, $selection, $paging, $user) : null;
            $profiled = (new DriverFactory)->profiled($instance, $options);

            if ($options->broadcasting !== null && ! $profiled->driver instanceof IdentityLookupDriver) {
                throw DapterException::configuration();
            }
            $read = NegotiatedRead::fromDriver($profiled->driver, $options, $paging, $selection, $version, $cursorAccess, $profiled->profile);
            $page = $read->execute();
            $actions = (new ActionRegistry)->metadata($instance, $options, $page->rows, $user);
            $position = $page->paging->toArray();
            $prepared = (new RowSerializer)->prepare($page->rows, $options, $live?->restored->request ?? clone $request, $live?->projection);

            $terms = array_map(static fn (OrderTerm $term): array => $term->toArray(), $selection->order);
            $order = $selection->preset !== null ? ['kind' => 'preset', 'id' => $selection->preset] : ($terms === [] ? ['kind' => 'default'] : ['kind' => 'fields', 'terms' => $terms]);
            $response = [
                'version' => $version,
                'data' => $prepared->data,
                'requested' => ['version' => $version, 'fields' => $selection->fields, 'global' => $selection->requestedGlobal($options), 'searchMatch' => $selection->searchMatch->value, 'order' => $order, 'paging' => $position],
                'state' => ['order' => ($version === 2 ? $page->effectiveOrder : null) ?? ($selection->order === [] ? ['kind' => OrderKind::Source->value] : ['kind' => 'terms', 'terms' => $terms]), 'paging' => $position],
                'paging' => $this->paging($page),
                'capabilities' => $read->wireCapabilities(),
            ];

            if ($version === 2) {
                $response['metadata'] = ['records' => $prepared->records, 'channel' => $live?->channel];

                if ($browserVersion !== null) {
                    $response['browserState'] = $options->browserState->toArray($source, $browserVersion);
                }
            }

            if ($actions !== []) {
                $response['actions'] = $actions;
            }

            return new JsonResponse($response);
        } catch (Throwable $exception) {
            $envelope = json_decode($request->getContent());

            if (is_object($envelope) && ($envelope->version ?? null) === 2) {
                $version = 2;
            }
            $error = $exception instanceof DapterException ? $exception : DapterException::configuration();
            $body = ['version' => $version, 'error' => ['kind' => $error->kind->value, 'code' => $error->errorCode, 'message' => $error->getMessage()]];

            if ($error->kind === ErrorKind::CursorExpired) {
                $body['error']['message'] = 'The cursor has expired. Restart pagination to continue.';
                $body['error']['recovery'] = 'restart';
            }

            if ($error->status === 422) {
                $body['errors'] = ['_dapter_kind' => [$error->kind->value]];

                if ($version === 2 && $error->errorCode === ErrorCode::InvalidCursor->value) {
                    $body['errors']['_dapter_code'] = [$error->errorCode];
                }
            }

            return new JsonResponse($body, $error->status);
        } finally {
            $request->setUserResolver($resolver);
        }
    }

    /** @return array<string,mixed> */
    private function paging(RawPage|SimplePage|CursorPage $page): array
    {
        $position = $page->paging->toArray();

        if ($page instanceof RawPage) {
            return [...$position, 'pages' => $page->pages, 'total' => $page->total, 'filtered' => $page->filtered ?? $page->total, 'has_previous' => $page->paging->current > 1, 'has_next' => $page->paging->current < $page->pages];
        }

        return [...$position, ...($page instanceof CursorPage ? ['current' => null] : []), 'pages' => null, 'total' => $page->counts?->total, 'filtered' => $page->counts?->filtered, 'previous_cursor' => $page instanceof CursorPage ? $page->previousCursor : null, 'next_cursor' => $page instanceof CursorPage ? $page->nextCursor : null, 'has_previous' => $page instanceof CursorPage ? $page->previousCursor !== null : $page->hasPrevious, 'has_next' => $page instanceof CursorPage ? $page->nextCursor !== null : $page->hasNext];
    }
}
