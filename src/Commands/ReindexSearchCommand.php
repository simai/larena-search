<?php

declare(strict_types=1);

namespace Larena\Search\Commands;

use Illuminate\Console\Command;
use InvalidArgumentException;
use Larena\Search\Exceptions\SearchPersistenceFailed;
use Larena\Search\Exceptions\SearchReindexRejected;
use Larena\Search\Reindex\SearchReindexService;
use Throwable;

final class ReindexSearchCommand extends Command
{
    protected $signature = 'search:reindex
        {provider : Registered search source provider ID}
        {--actor= : Explicit Access subject reference}
        {--run= : Existing run reference}
        {--operation= : Explicit existing-run operation: run, resume or retry}
        {--batch-size=100 : Projections per transaction}
        {--max-batches=0 : Stop after N checkpoints; zero runs to completion}
        {--schedule-only : Schedule without processing}';

    protected $description = 'Schedule, run, resume or retry a persistent, access-controlled Larena Search reindex.';

    public function __construct(private readonly SearchReindexService $reindex)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $providerId = (string) $this->argument('provider');
        $actor = (string) $this->option('actor');
        $runRef = $this->option('run');
        $runRef = is_string($runRef) && $runRef !== '' ? $runRef : null;
        $operation = $this->option('operation');
        $operation = is_string($operation) && $operation !== '' ? $operation : null;
        $batchSize = filter_var($this->option('batch-size'), FILTER_VALIDATE_INT);
        $maxBatches = filter_var($this->option('max-batches'), FILTER_VALIDATE_INT);

        if ($actor === '') {
            $this->components->error('An explicit --actor Access subject reference is required.');

            return self::FAILURE;
        }
        if (!is_int($batchSize) || !is_int($maxBatches)) {
            $this->components->error('Batch options must be integers.');

            return self::FAILURE;
        }
        if ($runRef !== null && (bool) $this->option('schedule-only')) {
            $this->components->error('--schedule-only cannot be combined with --run.');

            return self::FAILURE;
        }
        if (($runRef === null) !== ($operation === null) || ($operation !== null && !in_array($operation, ['run', 'resume', 'retry'], true))) {
            $this->components->error('--run requires an explicit --operation=run|resume|retry, and --operation requires --run.');

            return self::FAILURE;
        }

        try {
            if ($runRef !== null) {
                $run = match ($operation) {
                    'run' => $this->reindex->run($runRef, $actor, $batchSize, $maxBatches),
                    'resume' => $this->reindex->resume($runRef, $actor, $batchSize, $maxBatches, $providerId),
                    'retry' => $this->reindex->retry($runRef, $actor, $batchSize, $maxBatches, $providerId),
                };
            } else {
                $run = $this->reindex->schedule($providerId, $actor);
                if (!(bool) $this->option('schedule-only')) {
                    $run = $this->reindex->run($run->runRef, $actor, $batchSize, $maxBatches);
                }
            }

            $this->line(json_encode([
                'run_ref' => $run->runRef,
                'provider_id' => $run->providerId,
                'state' => $run->state,
                'processed_count' => $run->processedCount,
                'batch_count' => $run->batchCount,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (SearchReindexRejected|SearchPersistenceFailed|InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable) {
            $this->components->error('search_reindex_failed');

            return self::FAILURE;
        }
    }
}
