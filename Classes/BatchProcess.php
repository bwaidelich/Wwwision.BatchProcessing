<?php
declare(strict_types=1);
namespace Wwwision\BatchProcessing;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Booting\Exception\SubProcessException;
use Neos\Flow\Core\Booting\Scripts;
use Neos\Flow\Exception;
use React\ChildProcess\Process;

final class BatchProcess
{

    private const PIPE_STDIN = 0;
    private const PIPE_STDOUT = 1;
    private const PIPE_STDERR = 2;
    private const PIPE_PROGRESS = 3;

    private const EVENT_PROGRESS = 'progress';
    private const EVENT_FINISH = 'finish';
    private const EVENT_ERROR = 'error';

    private array $eventHandlers = [];

    /**
     * @Flow\InjectConfiguration(package="Neos.Flow")
     */
    protected array $flowSettings;

    private string $commandIdentifier;
    private array $commandArguments;
    private ?Process $process = null;

    public function __construct(string $commandIdentifier, array $commandArguments)
    {
        $this->commandIdentifier = $commandIdentifier;
        $this->commandArguments = $commandArguments;
    }

    public function start(): void
    {
        $process = $this->getProcess();
        $process->start();
        $output = [];
        $lastProgress = 0;
        $process->pipes[self::PIPE_PROGRESS]->on('data', function($chunk) use (&$lastProgress) {
            if (preg_match('/(\d+)$/', trim($chunk), $matches) !== 1) {
                throw new \RuntimeException(sprintf('Unexpected chunk: %s', $chunk), 1639058272);
            }
            $progress = (int)$matches[1];
            $this->dispatch(self::EVENT_PROGRESS, $progress, $progress - $lastProgress);
            $lastProgress = $progress;
        });
        $process->pipes[self::PIPE_STDOUT]->on('data', function($chunk) use (&$output) {
            $output[] = $chunk;
        });
        $process->pipes[self::PIPE_STDERR]->on('data', function($chunk) {
            $this->dispatch(self::EVENT_ERROR, trim($chunk));
        });
        $process->on('exit', function ($code) use (&$output) {
            if ($code !== 0) {
                $exceptionMessage = $output !== [] ? implode(PHP_EOL, $output) : sprintf('Execution of subprocess failed with exit code %d without any further output. (Please check your PHP error log for possible Fatal errors)', $code);
                throw new SubProcessException($exceptionMessage, 1639060452);
            }
            $this->dispatch(self::EVENT_FINISH);
        });
    }

    public function onProgress(callable $handler): void
    {
        $this->on(self::EVENT_PROGRESS, $handler);
    }

    public function onError(callable $handler): void
    {
        $this->on(self::EVENT_ERROR, $handler);
    }

    public function onFinish(callable $handler): void
    {
        $this->on(self::EVENT_FINISH, $handler);
    }

    /* --------- */

    private function getProcess(): Process
    {
        if ($this->process === null) {
            $fds = [
                self::PIPE_STDIN => ['pipe', 'r'],
                self::PIPE_STDOUT => ['pipe', 'w'],
                self::PIPE_STDERR => ['pipe', 'w'],
                self::PIPE_PROGRESS => ['pipe', 'w'],
            ];
            $this->process = new Process($this->buildSubprocessCommand(), FLOW_PATH_ROOT, null, $fds);
        }
        return $this->process;
    }

    private function buildSubprocessCommand(): string
    {
        try {
            $command = Scripts::buildPhpCommand($this->flowSettings);
        } catch (Exception $e) {
            throw new \RuntimeException(sprintf('Failed to build PHP command: %s', $e->getMessage()), 1639153583, $e);
        }
        if (isset($this->flowSettings['core']['subRequestIniEntries']) && \is_array($this->flowSettings['core']['subRequestIniEntries'])) {
            foreach ($this->flowSettings['core']['subRequestIniEntries'] as $entry => $value) {
                $command .= ' -d ' . escapeshellarg($entry);
                if (trim($value) !== '') {
                    $command .= '=' . escapeshellarg(trim((string)$value));
                }
            }
        }
        $escapedArguments = '';
        foreach ($this->commandArguments as $argument => $argumentValue) {
            $argumentValue = trim((string)$argumentValue);
            $escapedArguments .= ' ' . escapeshellarg('--' . trim($argument)) . ($argumentValue !== '' ? '=' . escapeshellarg($argumentValue) : '');
        }
        $command .= sprintf(' %s %s %s', escapeshellarg(FLOW_PATH_FLOW . 'Scripts/flow.php'), escapeshellarg($this->commandIdentifier), trim($escapedArguments));
        return trim($command);
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
