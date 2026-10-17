<?php

declare(strict_types=1);

namespace Larena\Search\Admin;

/** A provider of the search index as the cells of its row and its record, and the reindex steps its state allows. */
final class SearchProviderRows
{
    /** The reindex step each state allows, with the state the step expects. */
    public const STEPS = ['schedule' => ['idle', 'completed'], 'run' => ['scheduled'], 'resume' => ['running'], 'retry' => ['failed']];

    /**
     * @param array<string, scalar|null> $provider
     * @param callable(string): string $text
     * @return array<string, string>
     */
    public static function cells(array $provider, callable $text): array
    {
        $state = (string) $provider['state'];
        $label = $text('states.'.$state);

        return [
            'provider' => (string) $provider['provider_id'],
            'state' => str_starts_with($label, 'larena-search::') ? $state : $label,
            'progress' => $provider['processed_count'].' / '.$provider['batch_count'],
            'generation' => $provider['generation_ref'] ? substr((string) $provider['generation_ref'], 0, 20) : '—',
            'error' => (string) ($provider['error_code'] ?? '—'),
        ];
    }

    /**
     * The steps the provider's state allows now; run, resume and retry need its current run.
     *
     * @param array<string, scalar|null> $provider
     * @return list<string>
     */
    public static function steps(array $provider): array
    {
        $steps = [];
        foreach (self::STEPS as $step => $states) {
            if (in_array($provider['state'], $states, true) && ($step === 'schedule' || is_string($provider['run_ref'] ?? null))) {
                $steps[] = $step;
            }
        }

        return $steps;
    }
}
