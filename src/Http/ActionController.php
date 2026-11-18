<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Http;

use ArtisanToolbox\Dapter\Actions\ActionContext;
use ArtisanToolbox\Dapter\Actions\ActionRegistry;
use ArtisanToolbox\Dapter\Actions\ActionTargetUrl;
use ArtisanToolbox\Dapter\Broadcasting\RecipientContexts;
use ArtisanToolbox\Dapter\Enums\AccessMode;
use ArtisanToolbox\Dapter\Enums\ActionTransport;
use ArtisanToolbox\Dapter\Enums\ValueType;
use ArtisanToolbox\Dapter\Options\OptionsResolver;
use ArtisanToolbox\Dapter\Support\SourceRegistry;
use ArtisanToolbox\Dapter\Support\SourceResolver;
use Illuminate\Container\Container;
use Illuminate\Contracts\Auth\Access\Gate;
use Illuminate\Contracts\Auth\Factory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use ReflectionNamedType;

final class ActionController
{
    public function __invoke(Request $request, string $source, string $action, ?string $target = null): mixed
    {
        $previous = $request->getUserResolver();

        try {
            try {
                $live = resolve(SourceRegistry::class)->live($source);

                if ($live === null) {
                    $instance = (new SourceResolver)->resolve($source);
                    $options = (new OptionsResolver)->resolve($instance);

                    $user = resolve(Factory::class)->guard($options->guard)->user();
                } else {
                    [$instance, $options, $user] = resolve(RecipientContexts::class)->action($live, $request);
                }
                abort_if($options->access === AccessMode::Required && $user === null, 401);
                $request->setUserResolver(static fn (?string $guard = null) => $user);

                if ($live === null && $options->authorization !== null) {
                    resolve(Gate::class)->forUser($user)->authorize($options->authorization->ability, $options->authorization->arguments);
                }

                if ($live === null && $options->broadcasting !== null) {
                    throw DapterException::configuration();
                }
                $registry = new ActionRegistry;
                $definitions = $registry->discover($instance, $options);
            } catch (DapterException $exception) {
                abort($exception->status, $exception->getMessage());
            }
            $definition = $definitions[$action] ?? null;
            abort_if($definition === null, 404);
            abort_unless(strtoupper($definition->method->value) === $request->method(), 405);
            $transport = $request->header('X-Dapter-Transport');
            $native = $request->header('X-Inertia') === 'true' && ! $request->expectsJson() ? 'visit' : (! $request->headers->has('X-Inertia') && $request->expectsJson() ? 'http' : null);

            if ($request->header('X-Dapter-Action') !== '1' || ! in_array($transport, ['visit', 'http'], true) || $native !== $transport || ! in_array(ActionTransport::from($transport), $definition->transports, true)) {
                throw new HttpResponseException(response()->json(['message' => 'The action transport is not supported.', 'code' => 'unsupported_action_transport'], 406));
            }
            $record = null;
            $mandatory = null;
            $policy = $definition->policy;

            if ($policy?->target !== null) {
                $selector = $policy->target;
                $original = ActionInput::value($request, $selector->input);
                $input = $original;

                if (str_starts_with($request->header('Content-Type', ''), 'multipart/form-data') && $selector->valueType === ValueType::Integer && is_string($input) && preg_match('/\A(?:0|-?[1-9][0-9]*)\z/D', $input) && abs((float) $input) <= 9007199254740991) {
                    $input = (int) $input;
                }

                try {
                    $wire = $registry->selector($input, $selector);
                } catch (DapterException) {
                    abort(422);
                }
                abort_unless(ActionTargetUrl::matches($request, $target, $wire), 422);
                $request->merge([$selector->input => $original]);
                $mandatory = $registry->mandatory($instance, forRecord: true);
                $record = $registry->record($mandatory, $selector, $wire);
                abort_if($record === null, 404);
                resolve(Gate::class)->forUser($user)->authorize($policy->ability, $registry->recordArguments($record, $policy->arguments));
            } else {
                abort_unless(ActionTargetUrl::matches($request, $target, null), 422);

                if ($policy !== null) {
                    resolve(Gate::class)->forUser($user)->authorize($policy->ability, $policy->arguments);
                }
            }
            $form = null;

            if ($definition->formRequest !== null) {
                $class = $definition->formRequest;
                $form = FormRequest::createFrom($request, Container::getInstance()->build($class));
                $form->setContainer(Container::getInstance())->setRedirector(resolve('redirect'));
                $form->validateResolved();
            }
            $arguments = [];
            foreach ($definition->handler->getParameters() as $parameter) {
                $type = $parameter->getType();

                if (! $type instanceof ReflectionNamedType) {
                    throw DapterException::configuration();
                }$name = $type->getName();
                $arguments[] = match (true) {
                    $name === ActionContext::class => new ActionContext($record, $mandatory ??= $registry->mandatory($instance)),$form !== null && is_a($form, $name) => $form,is_a($name, Request::class, true) => $request,default => Container::getInstance()->make($name),
                };
            }
            $owner = $definition->owner === null ? $instance : Container::getInstance()->build($definition->owner);

            return $definition->handler->invokeArgs($owner, $arguments);
        } finally {
            $request->setUserResolver($previous);
        }
    }
}
