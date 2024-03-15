<?php

namespace Botble\DevTool\Commands;

use Botble\DevTool\Commands\Abstracts\BaseMakeCommand;
use Botble\DevTool\Helper;
use Botble\PluginManagement\Commands\Concern\HasPluginNameValidation;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\text;

#[AsCommand('cms:plugin:create', 'Create a new plugin.')]
class PluginCreateCommand extends BaseMakeCommand implements PromptsForMissingInput
{
    use HasPluginNameValidation;

    protected array $componentAvailableOfPlugins = [];

    protected bool $hasCrud = false;

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

        $components = [
            'helpers' => 'Helpers',
            'config' => 'Config',
            'database' => 'Database',
            'permissions' => 'Permissions',
            'translations' => 'Translations',
            'views' => 'Views',
            'routes' => 'Routes',
            'publishing_assets' => 'Publishing assets',
        ];

        $this->hasCrud = confirm('Do you want to add an example of CRUD?');

        if ($this->hasCrud) {
            $this->componentAvailableOfPlugins = ['permissions', 'database', 'translations', 'routes'];
        }

        $this->componentAvailableOfPlugins = [
            ...$this->componentAvailableOfPlugins,
            ...multiselect(
                'Your plugin want have available?',
                Arr::except($components, $this->componentAvailableOfPlugins)
            ),
        ];

        $this->publishStubs($this->getStub(), $location);

        File::copy(
            Helper::joinPaths([dirname(__DIR__, 2), 'stubs', 'plugin', 'plugin.json']),
            Helper::joinPaths([$location, 'plugin.json'])
        );
        File::copy(
            Helper::joinPaths([dirname(__DIR__, 2), 'stubs', 'plugin', 'Plugin.stub']),
            Helper::joinPaths([$location, 'src', 'Plugin.php'])
        );

        File::deleteDirectory(Helper::joinPaths([$location, 'src/Providers']));

        $this->publishStubs(
            Helper::joinPaths([dirname(__DIR__, 2), 'stubs', 'plugin', 'src', 'Providers']),
            Helper::joinPaths([$location, 'src/Providers']),
        );

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
        return Helper::joinPaths([dirname(__DIR__, 2), 'stubs', 'module']);
    }

    protected function removeUnusedFiles(string $location): void
    {
        $deleteFiles = ['composer.json'];
        $deleteDirectories = [];
        $componentAvailableOfPlugins = $this->componentAvailableOfPlugins;

        if (! $this->hasCrud) {
            $deleteDirectories = ['src/Forms', 'src/Models', 'src/Tables', 'src/Http'];
        }

        if (! in_array('database', $componentAvailableOfPlugins) || ! $this->hasCrud) {
            $deleteDirectories[] = 'database';
        }

        if (! in_array('permissions', $componentAvailableOfPlugins)) {
            $deleteFiles[] = 'config/permissions.php';
        }

        if (! in_array('helpers', $componentAvailableOfPlugins)) {
            $deleteDirectories[] = 'helpers';
        }

        if (! in_array('translations', $componentAvailableOfPlugins)) {
            $deleteDirectories[] = 'resources/lang';
        }

        if (! in_array('views', $componentAvailableOfPlugins)) {
            $deleteDirectories[] = 'resources/views';
        }

        if (! in_array('routes', $componentAvailableOfPlugins)) {
            $deleteDirectories[] = 'routes';
        }

        foreach ($deleteDirectories as $directory) {
            $path = Helper::joinPaths([$location, $directory]);

            if (File::exists($path)) {
                File::deleteDirectory($path);
            }
        }

        foreach ($deleteFiles as $file) {
            $path = Helper::joinPaths([$location, $file]);

            if (File::exists($path)) {
                File::delete($path);
            }
        }

        foreach (File::directories($location) as $directory) {
            if (empty(File::files($directory)) && empty(File::directories($directory))) {
                File::deleteDirectory($directory);
            }
        }
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
            '{Module}' => Str::of(str_replace('\\\\', '\\', $this->argument('namespace'))),
            '{PluginId}' => $this->argument('id'),
            '{PluginName}' => ucfirst(str_replace('-', ' ', $this->argument('name'))),
            '{PluginNamespace}' => Str::of($this->argument('namespace'))->append('\\\\'),
            '{PluginServiceProvider}' => $this->argument('provider'),
            '{PluginAuthor}' => $this->argument('author'),
            '{PluginAuthorURL}' => $this->argument('author_url'),
            '{PluginVersion}' => $this->argument('version'),
            '{PluginDescription}' => $this->argument('description'),
            '{PluginMinimumCoreVersion}' => $this->argument('minimum_core_version'),
            '{PluginBootProvider}' => $this->bootServiceProviderContent(),
            '{PluginRegisterLanguage}' => $this->registerAdvancedLanguage(),
            '{PluginRegisterDashboardMenu}' => $this->registerDashboardMenuContent(),
            '{PluginServiceProviderImports}' => $this->importsServiceProvider(),
            '{PluginHandleMethodRemove}' => $this->pluginHandleMethodRemoveContent(),
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
            ->addArgument('minimum_core_version', InputArgument::OPTIONAL, 'Minimum Core Version');
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

            $answer = text(Arr::get($item, 'label'), default: $defaultValue);
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
            'minimum_core_version' => [
                'label' => 'Plugin Minimum Core Version',
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
        if (! str_contains($id, '/')) {
            $this->handlerError();

            exit(self::FAILURE);
        }

        $before = Str::before($id, '/');
        $after = Str::after($id, '/');

        if ((! preg_match('/^[a-z0-9\-_.\/]+$/i', $before)) || (! preg_match('/^[a-z0-9\-_.\/]+$/i', $after))) {
            $this->components->error('Only alphabetic characters are allowed.');

            exit(self::FAILURE);
        }
    }

    protected function handlerError(): void
    {
        $this->components->error('Plugin ID does not match the pattern: (ex: <vendor>/<name>)');
    }

    protected function bootServiceProviderContent(): string|null
    {
        $componentAvailableOfPlugins = $this->componentAvailableOfPlugins;

        $bootServiceProviderMethods = [];

        if (in_array('helpers', $componentAvailableOfPlugins)) {
            $bootServiceProviderMethods[] = 'loadHelpers()';
        }

        if (in_array('permissions', $componentAvailableOfPlugins)) {
            $bootServiceProviderMethods[] = 'loadAndPublishConfigurations(["permissions"])';
        }

        if (in_array('translations', $componentAvailableOfPlugins)) {
            $bootServiceProviderMethods[] = 'loadAndPublishTranslations()';
        }

        if (in_array('routes', $componentAvailableOfPlugins)) {
            $bootServiceProviderMethods[] = 'loadRoutes()';
        }

        if (in_array('views', $componentAvailableOfPlugins) || in_array('publishing_assets', $componentAvailableOfPlugins)) {
            $bootServiceProviderMethods[] = 'loadAndPublishViews()';
        }

        if (in_array('database', $componentAvailableOfPlugins)) {
            $bootServiceProviderMethods[] = 'loadMigrations()';
        }

        if (! $bootServiceProviderMethods) {
            return ';';
        }

        $content = implode(PHP_EOL . str_repeat(' ', 12) . '->', $bootServiceProviderMethods);

        return Str::of($content)
            ->prepend('->')
            ->rtrim(PHP_EOL . str_repeat(' ', 12))
            ->append(';')
            ->toString();
    }

    protected function registerAdvancedLanguage(): string|null
    {
        if (! $this->hasCrud) {
            return null;
        }

        return PHP_EOL . str_repeat(' ', 12)  . sprintf("if (defined('LANGUAGE_ADVANCED_MODULE_SCREEN_NAME')) {
                \Botble\LanguageAdvanced\Supports\LanguageAdvancedManager::registerModule(%s::class, [
                    'name',
                ]);
            }", Str::studly($this->argument('name')));
    }

    protected function registerDashboardMenuContent(): string|null
    {
        if (! $this->hasCrud) {
            return null;
        }

        $rawContent = "DashboardMenu::default()->beforeRetrieving(function () {
                DashboardMenu::registerItem([
                    'id' => 'cms-plugins-{-name}',
                    'priority' => 5,
                    'parent_id' => null,
                    'name' => 'plugins/{-name}::{-name}.name',
                    'icon' => 'fa fa-list',
                    'url' => route('{-name}.index'),
                    'permissions' => ['{-name}.index'],
                ]);
            });";

        return PHP_EOL . str_repeat(' ', 12) . str_replace('{-name}', $this->argument('name'), $rawContent);
    }

    protected function importsServiceProvider(): string|null
    {
        if (! $this->hasCrud) {
            return null;
        }

        $imports = ['Botble\Base\Facades\DashboardMenu'];

        $imports[] = sprintf('%s\Models\%s', str_replace('\\\\', '\\', $this->argument('namespace')), Str::studly($this->argument('name')));

        return Str::of(implode(';' . PHP_EOL . 'use ', $imports))->prepend('use ')->append(';' . PHP_EOL);
    }

    public function pluginHandleMethodRemoveContent(): string
    {
        if (! $this->hasCrud && ! in_array('database', $this->componentAvailableOfPlugins)) {
            return '//';
        }

        $content = ["Schema::dropIfExists('{names}')", "Schema::dropIfExists('{names}_translations')"];
        $rawContent = implode(';' . PHP_EOL . str_repeat(' ', 8), $content);

        return Str::of(
            str_replace(
                '{names}',
                Str::plural(str_replace('-', '_', $this->argument('name'))),
                $rawContent
            )
        )
            ->append(';');
    }
}
