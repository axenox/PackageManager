<?php
namespace axenox\PackageManager\Common;

use Symfony\Component\Process\Process;
use exface\Core\Formulas\Date;
use exface\Core\Formulas\Time;
use GuzzleHttp\Client;

class SelfUpdateInstaller {
    
    private $statusMessage = null;
    
    private $timestamp = null;
    
    private $installationSuccess = false;
    
    private $output = null;

        public function install(string $command, string $filePath)
        {
            $cmd = $command . " " . $filePath;
            yield "Installing " . end(explode("/", $filePath)) . "..." .PHP_EOL .PHP_EOL;
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
            $this->output = $process->getOutput();
            $this->setInstallationResult($process->IsSuccessful());
            if($this->getInstallationResult()){
                yield "Installation successful!";
            } else {
                yield "Installation failed!";
            }
        }
        
    public function getInstallationOutput() : ?string
    {
        return $this->output;
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
    
    public function getInstallationResult()
    {
        return $this->installationSuccess;
    }
    
    protected function setInstallationResult(bool $isSuccessful) 
    {
        if($isSuccessful){
            $this->installationSuccess = true;
        } else {
            $this->installationSuccess = false;
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