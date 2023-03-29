<?php
namespace axenox\PackageManager\Common;

use Symfony\Component\Process\Process;
use exface\Core\Formulas\Date;
use exface\Core\Formulas\Time;

class SelfUpdateInstaller {
    
    private $statusMessage = null;
    
    private $timer = 1;
    
    private $timestamp = null;

        public function install(string $command, string $filePath)
        {
            $cmd = $command . " " . $filePath;
            yield "Installing " . $filePath . "..." . PHP_EOL;
            yield $this->printLineDelimiter();
            /* @var $process \Symfony\Component\Process\Process */
            $process = Process::fromShellCommandline($cmd, null, null, null, 600);
            $process->start();
            while ($process->isRunning()) {
                if ($process->getOutput() !== $this->statusMessage){
                    yield $this->printProgress($process->getOutput());
                } else {
                    yield ".";
                }
            }
            yield $this->printLineDelimiter();
            yield $this->getInstallationResult($process->IsSuccessful());
    }
    
    protected function printProgress($output) : string
    {
        $progress = PHP_EOL . substr($output, strlen($this->statusMessage));
        $this->emptyBuffer();
        $this->statusMessage = $output;
        $this->timer = 1;
        return $progress;
    }
    
    protected function printLoadTimer() : string
    {
        sleep(1);
        $loadingOutput = ".";
        $this->emptyBuffer();
        $this->timer++;
        return $loadingOutput;
    }
    
    protected function getInstallationResult(bool $isSuccessful) {
        if($isSuccessful){
            return "Installation successful!";
        } else {
            return "Installation failed!";
        }
    }
    
    protected function emptyBuffer()
    {
        ob_flush();
        flush();
    }
    
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
}