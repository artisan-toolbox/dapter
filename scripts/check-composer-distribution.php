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
        'src/DapterServiceProvider.php',
        'src/CollectionDataSource.php',
        'src/EloquentDataSource.php',
        'src/StoreOptions.php',
        'src/Enums/ReadProtocolVersion.php',
        'src/Contracts/DataSource.php',
        'src/Contracts/CollectionSource.php',
        'src/Contracts/EloquentSource.php',
        'src/Contracts/WithOptions.php',
        'src/Console/Commands/MakeDataSource.php',
        'src/Http/DataController.php',
        'src/Http/OffsetRequest.php',
        'src/Options/AttributeSetting.php',
        'src/Options/GateCheck.php',
        'src/Options/OptionsResolver.php',
        'src/Options/ResolvedStoreOptions.php',
        'src/Attributes/Authorize.php',
        'src/Attributes/Guard.php',
        'src/Attributes/PageSize.php',
        'src/Attributes/PageSizeOptions.php',
        'src/Attributes/Paging.php',
        'src/Attributes/PublicAccess.php',
        'config/dapter.php',
        'routes/web.php',
        'resources/stubs/collection.stub',
        'resources/stubs/eloquent.stub',
        'package.json',
        'resources/js/index.ts',
        'resources/js/DataAdapter.vue',
        'resources/js/adapter.ts',
        'resources/js/useDataAdapter.ts',
        'resources/js/protocol.ts',
        'resources/contracts/read-v1.json',
        'dist/index.js',
        'dist/index.d.ts',
        'dist/index.d.ts.map',
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

        if ($name === false || preg_match('~^(?:vendor/|node_modules/|build/|tests/|workbench/|scripts/|\.github/|\.ai/|\.agents/|\.env(?:\.|$)|config/maintainer_secrets\.php$)~', $name) === 1) {
            throw new RuntimeException('Composer archive contains a development or private file.');
        }
    }
} finally {
    $archive->close();
}

fwrite(STDOUT, "Composer archive includes public resources and excludes development and private files.\n");
