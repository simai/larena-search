<?php

declare(strict_types=1);

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Larena\Search\Persistence\SearchText;

/**
 * Indexed search: each document gets its search text (SearchText::document, the
 * words of its title and searchable text, computed when it is written) in a side
 * table, with a FULLTEXT index on MySQL, and a sort key `title_key` with the
 * index (access_scope, locale, title_key, id) that lists hits in order without
 * sorting them.
 *
 * The search text has a table of its own because InnoDB cannot add a column in
 * place to a table with a FULLTEXT index; the documents table keeps its INSTANT
 * column changes. The side table is created empty with its index and the stopword
 * list off (every word is searchable), so no FULLTEXT index is built over
 * existing rows. On MySQL the column is added INSTANT and the index built INPLACE
 * with LOCK=NONE, so writers are not blocked; existing documents are filled in
 * chunks of 500 by id.
 */
return new class extends Migration
{
    private const DOCUMENTS = 'larena_search_documents';
    private const TEXTS = 'larena_search_document_texts';
    private const ORDER_INDEX = 'larena_search_document_order';
    private const FULLTEXT_INDEX = 'larena_search_document_text_fulltext';
    private const CHUNK = 500;

    public function up(): void
    {
        /** @var Connection $connection */
        $connection = Schema::getConnection();
        $mysql = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true);
        $documents = $connection->getTablePrefix() . self::DOCUMENTS;
        $texts = $connection->getTablePrefix() . self::TEXTS;

        if (!Schema::hasColumn(self::DOCUMENTS, 'title_key')) {
            if ($mysql) {
                $column = "ADD COLUMN `title_key` VARCHAR(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT ''";
                try {
                    $connection->statement('ALTER TABLE `' . $documents . '` ' . $column . ', ALGORITHM=INSTANT');
                } catch (QueryException) {
                    $connection->statement('ALTER TABLE `' . $documents . '` ' . $column . ', ALGORITHM=INPLACE, LOCK=NONE');
                }
            } else {
                Schema::table(self::DOCUMENTS, static function (Blueprint $table): void {
                    $table->string('title_key', 191)->default('');
                });
            }
        }

        if (!Schema::hasTable(self::TEXTS)) {
            if ($mysql) {
                $this->withoutStopwords($connection, static fn () => $connection->statement('CREATE TABLE `' . $texts . '` ('
                    . '`document_id` BIGINT UNSIGNED NOT NULL, '
                    . '`search_text` LONGTEXT NOT NULL, '
                    . 'PRIMARY KEY (`document_id`), '
                    . 'FULLTEXT KEY `' . self::FULLTEXT_INDEX . '` (`search_text`)'
                    . ') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC'));
            } else {
                Schema::create(self::TEXTS, static function (Blueprint $table): void {
                    $table->unsignedBigInteger('document_id')->primary();
                    $table->longText('search_text');
                });
            }
        }

        $this->backfill($connection);

        if (!in_array(self::ORDER_INDEX, Schema::getIndexListing(self::DOCUMENTS), true)) {
            if ($mysql) {
                $connection->statement('CREATE INDEX `' . self::ORDER_INDEX . '` ON `' . $documents . '` (`access_scope`, `locale`, `title_key`, `id`) ALGORITHM=INPLACE LOCK=NONE');
            } else {
                Schema::table(self::DOCUMENTS, static function (Blueprint $table): void {
                    $table->index(['access_scope', 'locale', 'title_key', 'id'], self::ORDER_INDEX);
                });
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(self::TEXTS);
        if (in_array(self::ORDER_INDEX, Schema::getIndexListing(self::DOCUMENTS), true)) {
            Schema::table(self::DOCUMENTS, static function (Blueprint $table): void {
                $table->dropIndex(self::ORDER_INDEX);
            });
        }
        if (Schema::hasColumn(self::DOCUMENTS, 'title_key')) {
            Schema::table(self::DOCUMENTS, static function (Blueprint $table): void {
                $table->dropColumn('title_key');
            });
        }
    }

    /**
     * The search text and sort key of the documents written before this migration.
     * The stored `searchable_text` is the lowercased title, a newline and the
     * lowercased searchable text; the title is taken off again so the two fields
     * stay apart, as a fresh write keeps them.
     */
    private function backfill(Connection $connection): void
    {
        $after = 0;
        while (true) {
            $rows = $connection->table(self::DOCUMENTS)
                ->where('id', '>', $after)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'title', 'searchable_text']);
            if ($rows->isEmpty()) {
                return;
            }
            $texts = [];
            $cases = [];
            $bindings = [];
            $ids = [];
            foreach ($rows as $row) {
                $id = (int) $row->id;
                $title = (string) $row->title;
                $stored = (string) $row->searchable_text;
                $prefix = mb_strtolower($title, 'UTF-8') . "\n";
                $body = str_starts_with($stored, $prefix) ? substr($stored, strlen($prefix)) : $stored;
                $texts[] = ['document_id' => $id, 'search_text' => SearchText::document($title, $body)];
                $cases[] = 'WHEN ? THEN ?';
                array_push($bindings, $id, SearchText::titleKey($title));
                $ids[] = $id;
                $after = $id;
            }
            $connection->table(self::TEXTS)->upsert($texts, ['document_id'], ['search_text']);
            $connection->update(
                'UPDATE ' . $connection->getQueryGrammar()->wrapTable(self::DOCUMENTS)
                . ' SET title_key = CASE id ' . implode(' ', $cases) . ' END'
                . ' WHERE id IN (' . implode(', ', array_fill(0, count($ids), '?')) . ')',
                array_merge($bindings, $ids),
            );
        }
    }

    /**
     * Runs DDL that builds a FULLTEXT index with the session's stopword list off: the
     * index keeps the setting it was built with, so every word is searchable.
     */
    private function withoutStopwords(Connection $connection, Closure $ddl): void
    {
        $previous = null;
        try {
            $previous = $connection->selectOne('select @@SESSION.innodb_ft_enable_stopword as enabled')?->enabled;
            $connection->statement('SET SESSION innodb_ft_enable_stopword = OFF');
        } catch (QueryException) {
            $previous = null;
        }
        try {
            $ddl();
        } finally {
            if ($previous !== null) {
                try {
                    $connection->statement('SET SESSION innodb_ft_enable_stopword = ' . ((int) $previous === 0 ? 'OFF' : 'ON'));
                } catch (QueryException) {
                }
            }
        }
    }
};
