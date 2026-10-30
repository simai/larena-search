<?php

declare(strict_types=1);

// larena/admin and larena/ui are optional for Search (stage 9 of the sites plan): the admin's routes, menu entry and
// provider record come only with the admin, the public page only with ui, and search:reindex always serves the node.

use Larena\Search\Commands\ReindexSearchCommand;

require dirname(__DIR__, 2).'/vendor/autoload.php';

function search_without_admin_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

/** @return array{admin_loadable:bool,ui_loadable:bool,routes:array<string,string>,navigation:list<string>,commands:list<string>} */
function search_boot(string $mode): array
{
    $script = dirname(__DIR__).'/Support/search-optional-packages-boot.php';
    $output = [];
    $exitCode = 0;
    exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($mode).' 2>&1', $output, $exitCode);
    search_without_admin_assert($exitCode === 0, "The provider did not boot ({$mode}): ".implode("\n", $output));

    return json_decode(implode("\n", $output), true, 512, JSON_THROW_ON_ERROR);
}

$none = search_boot('none');
search_without_admin_assert($none['admin_loadable'] === false && $none['ui_loadable'] === false, 'The proof must really run without the admin and ui classes.');
search_without_admin_assert($none['routes'] === [], 'Without the admin and ui no search route may exist.');
search_without_admin_assert($none['commands'] === [ReindexSearchCommand::class], 'search:reindex serves a node without them.');

$ui = search_boot('ui');
search_without_admin_assert($ui['admin_loadable'] === false && $ui['ui_loadable'] === true, 'This proof runs with ui and without the admin.');
search_without_admin_assert($ui['routes'] === ['larena.search.public' => 'search'], 'With ui alone only the public page exists.');
search_without_admin_assert($ui['commands'] === [ReindexSearchCommand::class], 'search:reindex does not depend on the admin.');

$admin = search_boot('admin');
search_without_admin_assert($admin['admin_loadable'] === true && $admin['ui_loadable'] === true, 'The admin and ui must be installed for development.');
search_without_admin_assert($admin['navigation'] === ['search.operations'], 'With the admin the menu entry stays: '.implode(', ', $admin['navigation']));
search_without_admin_assert($admin['commands'] === [ReindexSearchCommand::class], 'The console command stays.');
search_without_admin_assert($admin['routes'] === [
    'larena.search.public' => 'search',
    'larena.search.admin.index' => 'admin/search',
    'larena.search.admin.port' => 'admin/search/port',
    'larena.search.admin.record-port' => 'admin/search/record-port',
    'larena.search.admin.schedule' => 'admin/search/providers/{providerId}/schedule',
    'larena.search.admin.run' => 'admin/search/providers/{providerId}/runs/{runRef}/run',
    'larena.search.admin.resume' => 'admin/search/providers/{providerId}/runs/{runRef}/resume',
    'larena.search.admin.retry' => 'admin/search/providers/{providerId}/runs/{runRef}/retry',
], 'With the admin the routes stay as they were.');

$root = dirname(__DIR__, 2);
$adminNames = [];
$uiNames = [];
foreach (['src', 'routes', 'config'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        $relative = substr($file->getPathname(), strlen($root) + 1);
        if (preg_match('/Larena\\\\{1,2}Admin\\\\{1,2}/', $source) === 1) {
            $adminNames[] = $relative;
        }
        if (preg_match('/Larena\\\\{1,2}Ui\\\\{1,2}/', $source) === 1) {
            $uiNames[] = $relative;
        }
    }
}
sort($adminNames);
sort($uiNames);
// The provider record and the admin controller are reached only from routes/admin.php, which the provider loads only with the admin.
search_without_admin_assert($adminNames === [
    'src/Admin/SearchProviderPropertyViewPort.php',
    'src/Http/Controllers/SearchAdminController.php',
    'src/Navigation/SearchAdminNavigationContributor.php',
    'src/Providers/SearchServiceProvider.php',
], 'Only the admin\'s own pieces may name the admin: '.implode(', ', $adminNames));
search_without_admin_assert($uiNames === ['src/Http/PublicSearchAssets.php', 'src/Providers/SearchServiceProvider.php'], 'Only the public page may name ui: '.implode(', ', $uiNames));

$composer = json_decode((string) file_get_contents($root.'/composer.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (['larena/admin', 'larena/ui'] as $package) {
    search_without_admin_assert(!isset($composer['require'][$package]), "{$package} must not be required.");
    search_without_admin_assert(isset($composer['require-dev'][$package], $composer['suggest'][$package]), "{$package} is a development requirement and a suggestion.");
}

echo "SearchWithoutAdminTest passed.\n";
