<?php
declare(strict_types=1);
namespace Wwwision\BatchProcessing\ProgressHandler;

final class NullProgressHandler implements ProgressHandler
{
    public function start(int $total, int $poolSize): void {}
    public function batchStart(int $batchIndex, int $offset, int $limit): void {}
    public function batchProgress(int $batchIndex, int $current): void {}
    public function batchFinish(int $batchIndex): void {}
    public function finish(): void {}
}
