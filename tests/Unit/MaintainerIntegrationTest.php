<?php

declare(strict_types=1);

use ArtisanToolbox\Dapter\Dapter;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

it('synchronizes the selected npm version before building the frontend for release', function () {
    Process::fake();

    Dapter::beforeVersioning('1.0.0-beta.1', '1.0.0-beta.2');

    $root = dirname(__DIR__, 2);
    Process::assertRanInOrder([
        fn (PendingProcess $process): bool => $process->path === $root
            && $process->command === ['npm', 'version', '1.0.0-beta.2', '--no-git-tag-version', '--allow-same-version', '--ignore-scripts'],
        fn (PendingProcess $process): bool => $process->path === $root
            && $process->command === ['npm', 'run', 'release:build'],
    ]);
});

it('stops versioning when an npm release command fails', function (string $command, bool $runsBuild) {
    Process::fake([
        $command => Process::result(errorOutput: 'Frontend release failed.', exitCode: 1),
        '*' => Process::result(),
    ]);

    expect(fn () => Dapter::beforeVersioning('1.0.0-beta.1', '1.0.0-beta.2'))
        ->toThrow(ProcessFailedException::class, 'Frontend release failed.');

    if ($runsBuild) {
        Process::assertRan(['npm', 'run', 'release:build']);
    } else {
        Process::assertDidntRun(['npm', 'run', 'release:build']);
    }
})->with([
    'version update fails before the build' => ['*npm*version*', false],
    'frontend build fails' => ['*npm*release:build*', true],
]);

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

        $command = new SymfonyProcess([
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
