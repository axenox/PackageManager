<?php
namespace axenox\PackageManager\Common;

use Symfony\Component\Process\Process;
use exface\Core\Formulas\Date;
use exface\Core\Formulas\Time;

class SelfUpdateInstaller {
    
    private $statusMessage = null;
    
    private $timestamp = null;

        public function install(string $command, string $filePath)
        {
            $cmd = $command . " " . $filePath;
            yield "Installing " . $filePath . "..." . PHP_EOL;
            yield $this->printLineDelimiter();
            /* @var $process \Symfony\Component\Process\Process */
            $process = Process::fromShellCommandline($cmd, null, null, null, 600);
            $process->start();
            $this->timestamp = time();
            while ($process->isRunning()) {
                if ($process->getOutput() !== $this->statusMessage){
                    yield $this->printProgress($process->getOutput());
                } else {
                    yield $this->printLoadTimer();
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
        return $progress;
    }
    
    protected function printLoadTimer() : ?string
    {
        $diffTimestamp = time();
        if ($diffTimestamp > ($this->timestamp + 1)){
            $loadingOutput = ".";
            $this->timestamp = $diffTimestamp;
        } else {
            $loadingOutput = "";
        }
        $this->emptyBuffer();
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