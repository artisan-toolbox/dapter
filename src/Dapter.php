<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter;

use ArtisanToolbox\Maintainer\Versionable\Contracts\Versionable;
use ArtisanToolbox\Maintainer\Versionable\Contracts\WithReadmeHead;
use ArtisanToolbox\Maintainer\Versionable\Readme\Badge;
use ArtisanToolbox\Maintainer\Versionable\Readme\Head;

class Dapter implements Versionable, WithReadmeHead
{
    public const string VERSION = '1.0.0-alpha.1';

    public function readmeHead(): Head
    {
        return (new Head)
            ->withLogo(src: 'logo/dapter-icon.svg', alt: '', width: 88)
            ->withTitle('Dapter')
            ->withDescription('Data adapter foundations for Laravel and Inertia applications.')
            ->addBadge(Badge::make(
                link: 'https://atb.dev.br/packages/dapter/',
                imgSrc: 'https://img.shields.io/badge/Status-Preview-FF5947?style=flat-square',
                imgAlt: 'Preview',
            ))
            ->addBadge(Badge::make(
                link: 'https://atb.dev.br/packages/dapter/#requirements',
                imgSrc: 'https://img.shields.io/badge/PHP-%5E8.5-777BB4?style=flat-square',
                imgAlt: 'PHP',
            ))
            ->addBadge(Badge::make(
                link: 'https://atb.dev.br/packages/dapter/#requirements',
                imgSrc: 'https://img.shields.io/badge/Laravel-%5E13.0-FF2D20?style=flat-square',
                imgAlt: 'Laravel',
            ))
            ->addBadge(Badge::make(
                link: 'https://github.com/artisan-toolbox/dapter/actions/workflows/tests.yml?query=branch%3A1.x',
                imgSrc: 'https://img.shields.io/github/actions/workflow/status/artisan-toolbox/dapter/tests.yml?branch=1.x&label=Tests&style=flat-square',
                imgAlt: 'Tests',
            ))
            ->addBadge(Badge::make(
                link: 'https://atb.dev.br/packages/dapter/',
                imgSrc: 'https://img.shields.io/badge/Docs-Read-blue?style=flat-square',
                imgAlt: 'Docs',
            ));
    }
}
