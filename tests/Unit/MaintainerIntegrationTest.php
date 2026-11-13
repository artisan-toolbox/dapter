<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

it('regenerates the committed README head and preserves unmanaged content', function () {
    $root = dirname(__DIR__, 2);
    $files = new Filesystem;
    $project = sys_get_temp_dir().'/dapter-maintainer-'.bin2hex(random_bytes(8));
    $files->makeDirectory($project);

    try {
        $files->copy($root.'/composer.json', $project.'/composer.json');
        $files->copyDirectory($root.'/src', $project.'/src');
        $source = $files->get($root.'/README.md');
        $readme = $source."\n## Consumer content\n\nKeep this content.\n";
        $files->put($project.'/README.md', preg_replace(
            '/\A<!-- MAINTAINER:README_HEAD:START -->.*?<!-- MAINTAINER:README_HEAD:END -->\r?\n\r?\n/s',
            '',
            $readme,
            1,
        ));

        $command = new Process([
            PHP_BINARY,
            $root.'/vendor/artisan-toolbox/maintainer/builds/maintainer',
            'readme:update',
            '--no-interaction',
            '--no-ansi',
        ], $project);
        $command->setTimeout(30);
        $command->mustRun();

        expect($files->get($project.'/README.md'))->toBe($readme)
            ->toContain('<h1>Dapter</h1>', 'logo/dapter-icon.svg', 'branch=1.x');

        $command->mustRun();

        expect($files->get($project.'/README.md'))->toBe($readme)
            ->and($files->get($root.'/README.md'))->toBe($source);
    } finally {
        $files->deleteDirectory($project);
    }
});
