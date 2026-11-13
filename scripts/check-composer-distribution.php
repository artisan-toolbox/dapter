<?php

declare(strict_types=1);

$archive = new ZipArchive;
$path = dirname(__DIR__).'/build/distribution/dapter-preview.zip';

if ($archive->open($path) !== true) {
    throw new RuntimeException('Build the Composer archive before checking its contents.');
}

try {
    foreach ([
        'src/Dapter.php',
        'package.json',
        'resources/js/index.ts',
        'resources/boost/skills/dapter-development/SKILL.md',
        'logo/dapter-icon.svg',
        'logo/dapter-duotone.svg',
    ] as $required) {
        if ($archive->locateName($required) === false) {
            throw new RuntimeException('Composer archive is missing '.$required.'.');
        }
    }

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = $archive->getNameIndex($index);

        if ($name === false || preg_match('~^(?:vendor/|node_modules/|build/|tests/|\.ai/|\.agents/|\.env$|config/maintainer_secrets\.php$)~', $name) === 1) {
            throw new RuntimeException('Composer archive contains a development or private file.');
        }
    }
} finally {
    $archive->close();
}

fwrite(STDOUT, "Composer archive includes public resources and excludes development and private files.\n");
