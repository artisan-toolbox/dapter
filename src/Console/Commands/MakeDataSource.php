<?php

declare(strict_types=1);

namespace ArtisanToolbox\Dapter\Console\Commands;

use ArtisanToolbox\Dapter\Http\DapterException;
use ArtisanToolbox\Dapter\Support\SourceResolver;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

#[Description('Create a Dapter data source')]
#[Signature('make:datasource {name : Dotted data source name} {--eloquent : Generate an Eloquent source scaffold}')]
final class MakeDataSource extends Command
{
    public function handle(Filesystem $files): int
    {
        $name = $this->input->getArgument('name');

        if (! is_string($name)) {
            return self::FAILURE;
        }

        try {
            $class = (new SourceResolver)->className($name);
        } catch (DapterException) {
            $this->components->error('Use a dotted source name such as Catalog.Products.');

            return self::FAILURE;
        }
        $path = app_path('DataSources/'.str_replace('.', '/', $name).'.php');

        if ($files->exists($path)) {
            $this->components->error('The data source already exists.');

            return self::FAILURE;
        }
        $namespace = substr($class, 0, (int) strrpos($class, '\\'));
        $short = substr($class, (int) strrpos($class, '\\') + 1);
        $stub = $this->option('eloquent') ? 'eloquent' : 'collection';
        $content = str_replace(['{{ namespace }}', '{{ class }}'], [$namespace, $short], $files->get(__DIR__.'/../../../resources/stubs/'.$stub.'.stub'));
        $files->ensureDirectoryExists(dirname($path));
        $files->put($path, $content);
        $this->components->info('Data source created.');

        return self::SUCCESS;
    }
}
