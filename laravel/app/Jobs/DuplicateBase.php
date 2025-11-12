<?php

namespace App\Jobs;

use App\Models\EpisodeDuplication;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

abstract class DuplicateBase implements ShouldQueue {

    use Queueable;

    protected EpisodeDuplication $episodeDuplication;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $duplicationId,
        protected int $orgEpisodeId
    ) {
        // By not passing the objects, the serialized version of the jobs should be considerably smaller.
    }

    /**
     * Execute the job.
     */
    public function handle(): void {
        $this->episodeDuplication = EpisodeDuplication::find($this->duplicationId);

        // Feature flag gate (pseudo). If disabled, skip the job gracefully.
        if (!$this->featureEnabled()) {
            $this->log('info', 'Duplication feature disabled, skipping job');
            $this->release(30); // Release back into queue after 30 seconds. Assuming the feature will be re-enabled
            // at some point.
            return;
        }

        // Handle status transitions via switch
        switch ($this->episodeDuplication->status) {
            case 'pending':
                $this->episodeDuplication->update(['status' => 'in_progress']);
                $this->log('info', 'Status transitioned from pending to in_progress');
                // TODO: dispatch event duplication.started: id => $this->duplicationId, startedAt => now()
                break;

            case 'in_progress':
                // continue
                break;
            default:
                $this->log('info', 'Duplication job stopped', [
                    'current_status' => $this->episodeDuplication->status,
                ]);
                return;
        }

        try {
            $this->log('info', 'Starting ' . static::class);
            $this->handleDuplication();
        }
        catch (Throwable $e) {


            $this->episodeDuplication->update(['status' => 'failed']);
            $this->log('error', 'Fatal error during duplication', [
                'exception' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'exception_class' => get_class($e),
            ]);
            // TODO: dispatch event duplication.failed: id => $this->duplicationId, errorCode => get_class($e), message => $e->getMessage()
            throw $e;
        }
    }

    /**
     * Handle the duplication logic. Implement in child classes.
     */
    abstract protected function handleDuplication(): void;

    /**
     * Pseudo feature flag toggle. Return false to disable duplication jobs.
     */
    protected function featureEnabled(): bool {
        return TRUE;
    }

    /**
     * Log a message with context.
     */
    protected function log(string $level, string $message, array $context = []): void {
        $context = array_merge([
            'duplication_id' => $this->duplicationId,
            'org_episode_id' => $this->orgEpisodeId,
            'job_class' => static::class,
        ], $context);

        Log::channel("duplication")->$level($message, $context);
    }

}
