<?php

declare(strict_types=1);

/**
 * Times the public search on a throwaway database, before (the substring scan
 * this package used: LOWER(searchable_text) LIKE '%term%', ORDER BY title,
 * OFFSET) and after (DatabaseSearchIndex::queryPage), p50/p95/max in ms, as JSON.
 *
 *   php scripts/search-bench.php [--driver=sqlite|mysql] [--documents=100000] [--runs=25]
 *
 * SQLite uses a new temp file; MySQL a database created on the local server of
 * the root's ignored .env.auth-mfa-mysql-test and dropped at the end.
 */

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Support\Facades\Schema;
use Larena\Search\Contracts\SearchCursor;
use Larena\Search\Contracts\SearchQuery;
use Larena\Search\Persistence\DatabaseSearchIndex;
use Larena\Search\Persistence\SearchText;

require dirname(__DIR__) . '/vendor/autoload.php';

$options = getopt('', ['driver::', 'documents::', 'runs::']);
$driver = is_string($options['driver'] ?? null) ? $options['driver'] : 'sqlite';
$documents = max(1000, (int) ($options['documents'] ?? 100000));
$runs = max(3, (int) ($options['runs'] ?? 25));

$migrations = [
    '2026_07_13_000001_create_larena_search_documents.php',
    '2026_07_13_000002_create_larena_search_source_states.php',
    '2026_07_13_000003_create_larena_search_reindex_runs.php',
    '2026_07_13_000004_create_larena_search_provider_states.php',
    '2026_10_22_000101_create_larena_search_document_texts.php',
];

$cleanup = static function (): void {
};
if ($driver === 'mysql') {
    require dirname(__DIR__) . '/tests/Support/mysql-disposable-database.php';
    $disposable = larena_search_mysql_disposable_database('larena_search_bench_test_');
    $connection = larena_search_mysql_connect($disposable['config']);
    $cleanup = $disposable['drop'];
} else {
    $path = tempnam(sys_get_temp_dir(), 'larena-search-bench-');
    if (!is_string($path)) {
        throw new RuntimeException('Could not allocate the bench database.');
    }
    $capsule = new Capsule();
    $capsule->addConnection(['driver' => 'sqlite', 'database' => $path, 'prefix' => '']);
    $capsule->setAsGlobal();
    $connection = $capsule->getConnection();
    Schema::swap($connection->getSchemaBuilder());
    $cleanup = static function () use ($path): void {
        foreach ([$path, $path . '-wal', $path . '-shm', $path . '-journal'] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
    };
}

try {
    foreach ($migrations as $file) {
        $migration = require dirname(__DIR__) . '/database/migrations/' . $file;
        $migration->up();
    }

    // Documents as DatabaseSearchIndex writes them, in batches.
    mt_srand(100000);
    $vocabulary = [];
    for ($word = 0; $word < 3000; $word++) {
        $vocabulary[] = substr(md5('w' . $word), 0, mt_rand(4, 9));
    }
    $titles = ['Oak chair', 'Dining table', 'Руководство по установке', 'Installation guide', 'About the company', 'Ёлка', 'Спецификация'];
    $seedStarted = microtime(true);
    $batch = 1000;
    for ($start = 1; $start <= $documents; $start += $batch) {
        $rows = [];
        $texts = [];
        for ($id = $start; $id < $start + $batch && $id <= $documents; $id++) {
            $title = $titles[mt_rand(0, count($titles) - 1)] . ' ' . $vocabulary[mt_rand(0, 2999)];
            $words = [];
            for ($n = 0; $n < 24; $n++) {
                $words[] = $vocabulary[mt_rand(0, 2999)];
            }
            // Two documents in three say "common": page 500 of it exists.
            if ($id % 3 !== 0) {
                $words[] = 'common';
            }
            if ($id % 2500 === 0) {
                $words[] = 'rarity';
            }
            $body = implode(' ', $words);
            $rows[] = [
                'id' => $id,
                'provider_id' => $id % 2 === 0 ? 'docara.pages' : 'content.items',
                'source_ref' => 'doc:' . $id,
                'source_revision' => 1,
                'title' => $title,
                'title_key' => SearchText::titleKey($title),
                'locator' => '/docs/' . $id,
                'snippet' => null,
                'locale' => intdiv($id, 2) % 2 === 0 ? 'en' : 'ru',
                'access_scope' => $id % 4 === 3 ? 'staff' : 'public',
                'searchable_text' => mb_strtolower($title . "\n" . $body),
                'payload' => '{}',
                'projection_hash' => str_repeat('0', 64),
                'generation_ref' => null,
                'created_at' => '2026-10-21 00:00:00',
                'updated_at' => '2026-10-21 00:00:00',
            ];
            $texts[] = ['document_id' => $id, 'search_text' => SearchText::document($title, $body)];
        }
        $connection->table('larena_search_documents')->insert($rows);
        $connection->table('larena_search_document_texts')->insert($texts);
    }
    $seedSeconds = microtime(true) - $seedStarted;
    if ($connection->getDriverName() === 'mysql') {
        $connection->statement('ANALYZE TABLE `larena_search_documents`, `larena_search_document_texts`');
    } else {
        $connection->statement('ANALYZE');
    }

    $legacy = static function (string $term, int $offset) use ($connection): int {
        $needle = mb_strtolower(trim($term));
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle);

        return $connection->table('larena_search_documents')
            ->whereIn('access_scope', ['public'])
            ->whereRaw("LOWER(searchable_text) LIKE ? ESCAPE '!'", ['%' . $escaped . '%'])
            ->where('locale', 'en')
            ->orderBy('title')->orderBy('provider_id')->orderBy('source_ref')
            ->offset($offset)->limit(21)->get()->count();
    };
    $index = new DatabaseSearchIndex($connection);
    $indexed = static function (string $term, int $offset, ?string $after = null) use ($index): int {
        return count($index->queryPage(new SearchQuery($term, locale: 'en', accessScopes: ['public'], limit: 20, offset: $offset, after: $after))->hits);
    };
    // The cursor a reader holds on page 500 of the common word.
    $key = $connection->table('larena_search_documents as d')
        ->join('larena_search_document_texts as t', 't.document_id', '=', 'd.id')
        ->where('d.access_scope', 'public')->where('d.locale', 'en')
        ->whereRaw('t.search_text LIKE ?', ['% common%'])
        ->orderBy('d.title_key')->orderBy('d.id')->offset(9979)->limit(1)->first(['d.id', 'd.title_key']);
    $deepCursor = is_object($key) ? (new SearchCursor((string) $key->title_key, (int) $key->id))->encode() : null;

    $scenarios = [
        'common_word_page_1' => ['common', 0],
        'common_word_page_500' => ['common', 9980],
        'rare_word' => ['rarity', 0],
        'two_words' => ['oak chair', 0],
        'cyrillic_prefix' => ['руковод', 0],
        'short_term' => ['ok', 0],
    ];
    $time = static function (Closure $run) use ($runs): array {
        $run();
        $samples = [];
        $hits = 0;
        for ($n = 0; $n < $runs; $n++) {
            $started = hrtime(true);
            $hits = $run();
            $samples[] = (hrtime(true) - $started) / 1e6;
        }
        sort($samples);
        $at = static fn (float $share): float => round($samples[min(count($samples) - 1, (int) floor($share * (count($samples) - 1)))], 2);

        return ['p50' => $at(0.5), 'p95' => $at(0.95), 'max' => round($samples[count($samples) - 1], 2), 'hits' => $hits];
    };
    $results = [];
    foreach ($scenarios as $name => [$term, $offset]) {
        $results[$name] = [
            'before' => $time(static fn (): int => $legacy($term, $offset)),
            'after' => $time(static fn (): int => $indexed($term, $offset)),
        ];
    }
    if ($deepCursor !== null) {
        $results['common_word_page_500']['after_cursor'] = $time(static fn (): int => $indexed('common', 9980, $deepCursor));
    }

    echo json_encode([
        'driver' => $driver,
        'server' => (string) $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION),
        'documents' => $documents,
        'runs' => $runs,
        'seed_seconds' => round($seedSeconds, 1),
        'ms' => $results,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n";
} finally {
    $cleanup();
}
