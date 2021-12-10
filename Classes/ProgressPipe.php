<?php
declare(strict_types=1);
namespace Wwwision\BatchProcessing;

use Neos\Flow\Annotations as Flow;

/**
 * @Flow\Proxy(false)
 */
final class ProgressPipe
{

    private $handle;
    private int $current = 0;

    public function __construct()
    {
        $this->handle = fopen('php://fd/3', 'w');
    }

    public function advance(int $steps = 1): void
    {
        $this->set($this->current + $steps);
    }

    public function set(int $current): void
    {
        $this->current = $current;
        fwrite($this->handle, $this->current . \chr(10));
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $message);
    }

    public function __destruct()
    {
        fclose($this->handle);
    }
}
