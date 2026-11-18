<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\PHPStan;

use ArtisanToolbox\Dapter\Attributes\Action;
use ArtisanToolbox\Dapter\Attributes\GlobalFilter;
use ArtisanToolbox\Dapter\Attributes\GlobalOrder;
use PHPStan\Reflection\ExtendedMethodReflection;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Rules\Methods\AlwaysUsedMethodExtension;

/** @internal */
final class AttributedMethodExtension implements AlwaysUsedMethodExtension
{
    public function isAlwaysUsed(MethodReflection $methodReflection): bool
    {
        if (! $methodReflection instanceof ExtendedMethodReflection) {
            return false;
        }
        foreach ($methodReflection->getAttributes() as $attribute) {
            if (in_array($attribute->getName(), [Action::class, GlobalFilter::class, GlobalOrder::class], true)) {
                return true;
            }
        }

        return false;
    }
}
