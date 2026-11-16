<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Drivers\DriverFactory;
use ArtisanToolbox\Dapter\Drivers\NegotiatedRead;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\ErrorCode;
use ArtisanToolbox\Dapter\Enums\ErrorKind;
use ArtisanToolbox\Dapter\Enums\OrderKind;
use ArtisanToolbox\Dapter\Enums\ReadProtocolVersion;
use ArtisanToolbox\Dapter\Enums\SearchMatch;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Support\SourceResolver;
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

        try {
            $intent = ReadIntent::fromJson($request->getContent());
            $instance = (new SourceResolver)->resolve($source);
            $options = (new OptionsResolver)->resolve($instance);
            $user = resolve(Factory::class)->guard($options->guard)->user();

            throw_if($options->access === AccessMode::Required && $user === null, DapterException::class, ErrorKind::Access, ErrorCode::AuthenticationRequired, 401);
            $request->setUserResolver(static fn (?string $guard = null) => $user);

            if ($options->authorization !== null) {
                try {
                    resolve(Gate::class)->forUser($user)->authorize($options->authorization->ability, $options->authorization->arguments);
                } catch (AuthorizationException $exception) {
                    $status = $exception->status();
                    $status = is_int($status) && $status >= 400 && $status <= 599 ? $status : 403;

                    throw new DapterException(ErrorKind::Access, $status === 404 ? ErrorCode::SourceNotFound : ErrorCode::AccessDenied, $status);
                }
            }
            $paging = $intent->validate($options);
            $driver = (new DriverFactory)->make($instance);
            $read = NegotiatedRead::fromDriver($driver, $options, $paging);
            $page = $read->execute();
            $position = $page->paging->toArray();

            return new JsonResponse([
                'version' => ReadProtocolVersion::V1->value,
                'data' => (new RowSerializer)->serialize($page->rows),
                'requested' => ['version' => ReadProtocolVersion::V1->value, 'fields' => (object) [], 'global' => (object) [], 'searchMatch' => SearchMatch::All->value, 'order' => ['kind' => OrderKind::Default->value], 'paging' => $position],
                'state' => ['order' => ['kind' => OrderKind::Source->value], 'paging' => $position],
                'paging' => [...$position, 'pages' => $page->pages, 'total' => $page->total, 'filtered' => $page->total, 'has_previous' => $page->paging->current > 1, 'has_next' => $page->paging->current < $page->pages],
                'capabilities' => $read->wireCapabilities(),
            ]);
        } catch (Throwable $exception) {
            $error = $exception instanceof DapterException ? $exception : DapterException::configuration();
            $body = ['version' => ReadProtocolVersion::V1->value, 'error' => ['kind' => $error->kind->value, 'code' => $error->errorCode, 'message' => $error->getMessage()]];

            if ($error->status === 422) {
                $body['errors'] = ['_dapter_kind' => [$error->kind->value]];
            }

            return new JsonResponse($body, $error->status);
        } finally {
            $request->setUserResolver($resolver);
        }
    }
}
