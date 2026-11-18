<?php

declare(strict_types=1);

$archive = new ZipArchive;
$path = $argv[1] ?? dirname(__DIR__).'/build/distribution/dapter-preview.zip';

if ($archive->open($path) !== true) {
    throw new RuntimeException('Cannot open distribution archive '.$path.'.');
}

try {
    $manifest = $archive->getFromName('package.json');

    if ($manifest === false) {
        throw new RuntimeException('Distribution archive is missing package.json.');
    }

    $package = json_decode($manifest, true, flags: JSON_THROW_ON_ERROR);
    $entries = [$package['exports'], $package['imports'] ?? []];

    foreach (new RecursiveIteratorIterator(new RecursiveArrayIterator($entries)) as $target) {
        if (is_string($target) && str_starts_with($target, './') && $archive->locateName(substr($target, 2)) === false) {
            throw new RuntimeException('Distribution archive is missing package.json target '.$target.'.');
        }
    }

    foreach ([
        'extension.neon',
        'src/PHPStan/AttributedMethodExtension.php',
        'src/Dapter.php',
        'src/DapterServiceProvider.php',
        'src/CollectionDataSource.php',
        'src/EloquentDataSource.php',
        'src/StoreOptions.php',
        'src/DataStore.php',
        'src/Broadcasting/LiveSourceRegistration.php',
        'src/Broadcasting/RecipientReference.php',
        'src/Broadcasting/DataStores.php',
        'src/Broadcasting/RecipientContexts.php',
        'src/Broadcasting/PublishUpdates.php',
        'src/Broadcasting/CommitBoundary.php',
        'src/Broadcasting/UpdateEnvelope.php',
        'src/Drivers/SqlIdentitySelector.php',
        'src/Contracts/DeliveryContextFactory.php',
        'src/Contracts/PublicRowProjection.php',
        'src/Contracts/IdentityLookupDriver.php',
        'src/Contracts/RowIdentityProvider.php',
        'src/Contracts/RowRevisionProvider.php',
        'src/Contracts/ChannelContextResolver.php',
        'src/Attributes/UseResource.php',
        'src/Attributes/BroadcastUpdatable.php',
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
        'resources/js/index.ts',
        'resources/js/DataAdapter.vue',
        'resources/js/adapter.ts',
        'resources/js/useDataAdapter.ts',
        'resources/js/protocol.ts',
        'resources/js/records.ts',
        'resources/js/realtime.ts',
        'resources/contracts/read-v1.json',
        'resources/contracts/updates-v1.json',
        'dist/index.js',
        'dist/index.js.map',
        'dist/index.d.ts',
        'dist/index.d.ts.map',
        'resources/boost/skills/dapter-development/SKILL.md',
        'logo/dapter-icon.svg',
        'logo/dapter-duotone.svg',
    ] as $required) {
        if ($archive->locateName($required) === false) {
            throw new RuntimeException('Distribution archive is missing '.$required.'.');
        }
    }

    for ($index = 0; $index < $archive->numFiles; $index++) {
        $name = $archive->getNameIndex($index);

        if ($name === false || preg_match('~^(?:vendor/|node_modules/|build/|tests/|workbench/|scripts/|\.github/|\.ai/|\.agents/|\.env(?:\.|$)|config/maintainer_secrets\.php$)~', $name) === 1) {
            throw new RuntimeException('Distribution archive contains a development or private file.');
        }
    }
} finally {
    $archive->close();
}

fwrite(STDOUT, "Distribution archive includes public resources and excludes development and private files.\n");
