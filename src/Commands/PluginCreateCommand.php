<?php

namespace Botble\DevTool\Commands;

use Botble\DevTool\Commands\Abstracts\BaseMakeCommand;
use Botble\PluginManagement\Commands\Concern\HasPluginNameValidation;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand('cms:plugin:create', 'Create a plugin in the /platform/plugins directory.')]
class PluginCreateCommand extends BaseMakeCommand implements PromptsForMissingInput
{
    use HasPluginNameValidation;

    public function handle(): int
    {
        $this->components->info('Welcome to the Botble plugin generator');

        $plugin = [
            'id' => strtolower($this->argument('id')),
            'name' => strtolower($this->argument('name')),
        ];

        $this->validatePluginName($plugin['name']);
        $this->validatePluginId($plugin['id']);

        $location = plugin_path($plugin['name']);

        if (File::isDirectory($location)) {
            $this->components->error(sprintf('A plugin named [%s] already exists.', $plugin['name']));

            return self::FAILURE;
        }

        $this->publishStubs($this->getStub(), $location);
        File::copy(__DIR__ . '/../../stubs/plugin/plugin.json', sprintf('%s/plugin.json', $location));
        File::copy(__DIR__ . '/../../stubs/plugin/Plugin.stub', sprintf('%s/src/Plugin.php', $location));
        $this->renameFiles($plugin['name'], $location);
        $this->searchAndReplaceInFiles($plugin['name'], $location);
        $this->removeUnusedFiles($location);

        $this->components->info(
            sprintf('<info>The plugin</info> <comment>%s</comment> <info>was created in</info> <comment>%s</comment><info>, customize it!</info>', $plugin['name'], $location)
        );

        $this->call('cache:clear');

        return self::SUCCESS;
    }

    public function getStub(): string
    {
        return __DIR__ . '/../../stubs/module';
    }

    protected function removeUnusedFiles(string $location): void
    {
        File::delete(sprintf('%s/composer.json', $location));
    }

    public function getReplacements(string $replaceText): array
    {
        return [
            '{type}' => 'plugin',
            '{types}' => 'plugins',
            '{-module}' => strtolower($replaceText),
            '{module}' => Str::snake(str_replace('-', '_', $replaceText)),
            '{+module}' => Str::camel($replaceText),
            '{modules}' => Str::plural(Str::snake(str_replace('-', '_', $replaceText))),
            '{Modules}' => ucfirst(Str::plural(Str::snake(str_replace('-', '_', $replaceText)))),
            '{-modules}' => Str::plural($replaceText),
            '{MODULE}' => strtoupper(Str::snake(str_replace('-', '_', $replaceText))),
            '{Module}' => str($replaceText)
                ->replace('/', '\\')
                ->afterLast('\\')
                ->studly()
                ->prepend('Botble\\'),
            '{PluginId}' => $this->argument('id'),
            '{PluginName}' => $this->argument('name'),
            '{PluginNamespace}' => $this->argument('namespace'),
            '{PluginServiceProvider}' => $this->argument('provider'),
            '{PluginAuthor}' => $this->argument('author'),
            '{PluginAuthorURL}' => $this->argument('author_url'),
            '{PluginVersion}' => $this->argument('version'),
            '{PluginDescription}' => $this->argument('description'),
            '{PluginMiniumumCoreVersion}' => $this->argument('miniumum_core_version'),
        ];
    }

    protected function configure(): void
    {
        $this
            ->addArgument('id', InputArgument::REQUIRED, 'Plugin ID (ex: botble/example-name)')
            ->addArgument('name', InputArgument::OPTIONAL, 'Plugin Name')
            ->addArgument('description', InputArgument::OPTIONAL, 'Plugin Description')
            ->addArgument('namespace', InputArgument::OPTIONAL, 'Plugin Namespace')
            ->addArgument('provider', InputArgument::OPTIONAL, 'Plugin Provider')
            ->addArgument('author', InputArgument::OPTIONAL, 'Plugin Author')
            ->addArgument('author_url', InputArgument::OPTIONAL, 'Plugin Author URL')
            ->addArgument('version', InputArgument::OPTIONAL, 'Plugin Version')
            ->addArgument('miniumum_core_version', InputArgument::OPTIONAL, 'Miniumum Core Version');
    }

    protected function afterPromptingForMissingArguments(InputInterface $input, OutputInterface $output): void
    {
        foreach ($this->inputOptions() as $key => $item) {
            $pluginId = Str::after($this->argument('id'), '/');

            $pluginNamespace = Str::studly($pluginId);
            $pluginName = Str::kebab($pluginId);

            $defaultValue = Arr::get($item, 'default');

            if (str_contains($defaultValue, '{plugin-name}')) {
                $defaultValue = str_replace('{plugin-name}', $pluginName, $defaultValue);
            }

            if (str_contains($defaultValue, '{PluginName}')) {
                $defaultValue = str_replace('{PluginName}', $pluginNamespace, $defaultValue);
            }

            if (str_contains($defaultValue, '{Namespace}')) {
                $defaultValue = str_replace('{Namespace}', $this->argument('namespace'), $defaultValue);
            }

            if (str_contains($defaultValue, '/')) {
                $defaultValue = $this->replaceNamespace($defaultValue);
            }

            $answer = $this->ask(Arr::get($item, 'label'), $defaultValue);

            $input->setArgument($key, $answer);
        }
    }

    public function inputOptions(): array
    {
        return [
            'name' => [
                'label' => 'Plugin Name',
                'default' => '{plugin-name}',
            ],
            'description' => [
                'label' => 'Plugin Description',
                'default' => 'This is a Botble plugin generated by DevTool',
            ],
            'namespace' => [
                'label' => 'Plugin Namespaces',
                'default' => 'Botble/{PluginName}',
            ],
            'provider' => [
                'label' => 'Plugin Provider',
                'default' => '{Namespace}/Providers/{PluginName}ServiceProvider',
            ],
            'author' => [
                'label' => 'Plugin Author',
                'default' => '',
            ],
            'author_url' => [
                'label' => 'Plugin Author URL',
                'default' => '',
            ],
            'version' => [
                'label' => 'Plugin Version',
                'default' => '1.0.0',
            ],
            'miniumum_core_version' => [
                'label' => 'Plugin Miniumum Core Version',
                'default' => get_core_version(),
            ],
        ];
    }

    protected function replaceNamespace(string $namespace): string
    {
        if (str_contains($namespace, '/')) {
            return str_replace('/', '\\\\', $namespace);
        }

        if (str_contains($namespace, '\\')) {
            return str_replace('\\', '\\\\', $namespace);
        }

        return $namespace;
    }

    protected function validatePluginId(string $id): void
    {
        if (! preg_match('/^[a-z0-9\-_.\/]+$/i', $id)) {
            $this->components->error('Only alphabetic characters are allowed.');

            exit(self::FAILURE);
        }

        if (! str_contains($id, '/')) {
            $this->handlerError();

            exit(self::FAILURE);
        }

        $before = Str::before($id, '/');
        $after = Str::after($id, '/');

        if (! strlen($before) || ! strlen($after)) {
            $this->handlerError();

            exit(self::FAILURE);
        }
    }

    protected function handlerError(): void
    {
        $this->components->error('Plugin ID does not match the pattern: (ex: <vendor>/<name>)');
    }
}
