<?php
declare(strict_types=1);
namespace Wwwision\BatchProcessing;

use Wwwision\BatchProcessing\ProgressHandler\NullProgressHandler;
use Wwwision\BatchProcessing\ProgressHandler\ProgressHandler;


final class BatchProcessRunner
{
    private const BATCH_SIZE_DEFAULT = 500;
    private const POOL_SIZE_DEFAULT = 5;
    private const COMMAND_ARGUMENTS_DEFAULT = ['offset' => '{offset}', 'limit' => '{limit}'];

    private string $batchCommandIdentifier;
    private array $batchCommandArguments;
    private ProgressHandler $progressHandler;

    private int $batchSize = self::BATCH_SIZE_DEFAULT;
    private int $poolSize = self::POOL_SIZE_DEFAULT;
    private array $queue = [];
    private array $pool = [];
    private array $eventHandlers = [];
    private array $errors = [];

    private const EVENT_FINISH = 'finish';
    private const EVENT_ERROR = 'error';

    public function __construct(string $batchCommandIdentifier, array $batchCommandArguments = null, ProgressHandler $progressHandler = null, int $batchSize = null, int $poolSize = null)
    {
        $this->batchCommandIdentifier = $batchCommandIdentifier;
        $this->batchCommandArguments = $batchCommandArguments ?? self::COMMAND_ARGUMENTS_DEFAULT;
        $this->progressHandler = $progressHandler ?? new NullProgressHandler();
    }

    public function setBatchSize(int $batchSize): void
    {
        $this->batchSize = $batchSize;
    }

    public function setPoolSize(int $poolSize): void
    {
        $this->poolSize = $poolSize;
    }

    public function start(int $total): void
    {
        $this->queue = [];
        $this->pool = [];
        $this->errors = [];
        $numberOfBatches = (int)ceil($total / $this->batchSize);
        for($i = 0; $i < $numberOfBatches; $i ++) {
            $offset = $i * $this->batchSize;
            $limit = min($this->batchSize, $total - $offset);
            $this->queue[] = compact('i', 'offset', 'limit');
        }
        $this->progressHandler->start($total, $this->poolSize);
        $this->populatePool();
    }

    public function onFinish(callable $handler): void
    {
        $this->on(self::EVENT_FINISH, $handler);
    }

    public function onError(callable $handler): void
    {
        $this->on(self::EVENT_ERROR, $handler);
    }


    /* --------------------- */

    private function populatePool(): void
    {
        while (\count($this->pool) < $this->poolSize) {
            $batch = array_shift($this->queue);
            if ($batch === null) {
                return;
            }
            $this->spawnBatchProcess($batch['i'], $batch['offset'], $batch['limit']);
        }
    }

    private function spawnBatchProcess(int $batchId, int $offset, int $limit): void
    {
        $batchProcess = new BatchProcess($this->batchCommandIdentifier, $this->batchCommandArguments($offset, $limit));
        $this->progressHandler->batchStart($batchId, $offset, $limit);

        $batchProcess->onProgress(fn (int $current) => $this->progressHandler->batchProgress($batchId, $current));
        $batchProcess->onFinish(function() use ($batchId) {
            $this->progressHandler->batchFinish($batchId);
            unset($this->pool[$batchId]);
            if ($this->queue === [] && $this->pool === []) {
                $this->progressHandler->finish();
                $this->dispatch(self::EVENT_FINISH, $this->errors);
                return;
            }
            $this->populatePool();
        });
        $batchProcess->onError(function($message) {
            $this->errors[] = $message;
            $this->dispatch(self::EVENT_ERROR, $message);
        });
        $batchProcess->start();
        $this->pool[$batchId] = $batchProcess;
    }

    private function batchCommandArguments(int $offset, int $limit): array
    {
        return array_map(static fn($argument) => \is_string($argument) ? str_replace(['{offset}', '{limit}'], [$offset, $limit], $argument) : $argument, $this->batchCommandArguments);
    }

    private function on(string $event, callable $handler): void
    {
        if (!isset($this->eventHandlers[$event])) {
            $this->eventHandlers[$event] = [];
        }
        $this->eventHandlers[$event][] = $handler;
    }

    private function dispatch(string $event, ...$arguments): void
    {
        foreach ($this->eventHandlers[$event] ?? [] as $handler) {
            $handler(...$arguments);
        }
    }

}
