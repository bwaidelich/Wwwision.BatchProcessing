<?php
declare(strict_types=1);
namespace Wwwision\BatchProcessing\ProgressHandler;

use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Output\ConsoleSectionOutput;
use Symfony\Component\Console\Output\OutputInterface;

final class ProgressBarRenderer implements ProgressHandler
{
    private OutputInterface $output;
    private ProgressBar $mainProgressBar;
    private int $poolSize = 1;
    /** @var ProgressBar[] */
    private array $progressBars = [];
    /** @var ConsoleSectionOutput[] */
    private array $sections = [];
    private array $lastProgressBarValues = [];
    private array $limits = [];

    private function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $mainSection = ($this->output instanceof ConsoleOutput) ? $this->output->section() : $this->output;
        $this->mainProgressBar = new ProgressBar($mainSection);
        $this->mainProgressBar->setBarCharacter('<success>●</success>');
        $this->mainProgressBar->setEmptyBarCharacter('<error>◌</error>');
        $this->mainProgressBar->setProgressCharacter('<success>●</success>');
        $this->mainProgressBar->setFormat('debug');
    }

    public static function create(OutputInterface $output): self
    {
        return new self($output);
    }

    public function start(int $total, int $poolSize): void
    {
        $this->mainProgressBar->start($total);
        $this->poolSize = $poolSize;
    }

    public function batchStart(int $batchIndex, int $offset, int $limit): void
    {
        $this->limits[$batchIndex] = $limit;
        if ($this->poolSize === 1 || !$this->output instanceof ConsoleOutput) {
            return;
        }
        $this->sections[$batchIndex] = $this->output->section();
        $this->progressBars[$batchIndex] = new ProgressBar($this->sections[$batchIndex]);
        $this->progressBars[$batchIndex]->setMessage($offset . ' - ' . ($offset + $limit));
        $this->progressBars[$batchIndex]->setFormat('     [%bar%] %message%');
        $this->progressBars[$batchIndex]->start($limit);
    }

    public function batchProgress(int $batchIndex, int $current): void
    {
        $this->mainProgressBar->advance($current - ($this->lastProgressBarValues[$batchIndex] ?? 0));
        $this->lastProgressBarValues[$batchIndex] = $current;
        if ($this->poolSize !== 1) {
            $this->progressBars[$batchIndex]->setProgress($current);
        }
    }

    public function batchFinish(int $batchIndex): void
    {
        $this->mainProgressBar->advance($this->limits[$batchIndex] - ($this->lastProgressBarValues[$batchIndex] ?? 0));
        if ($this->poolSize !== 1) {
            $this->progressBars[$batchIndex]->finish();
            $this->sections[$batchIndex]->clear();
        }
    }

    public function finish(): void
    {
        $this->mainProgressBar->finish();
    }
}
