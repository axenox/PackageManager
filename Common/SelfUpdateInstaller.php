<?php
namespace axenox\PackageManager\Common;

use Symfony\Component\Process\Process;

class SelfUpdateInstaller {
    
    private $statusMessage = null;
    
    private $timestamp = null;
    
    private $installationStatus = false;
    
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

    /**
     * 
     * @param string $output
     * @return string
     */
    protected function printProgress(string $output) : string
    {
        $progress = substr($output, strlen($this->statusMessage));
        $this->emptyBuffer();
        $this->statusMessage = $output;
        return $progress;
    }
    
    /**
     * 
     * @return string|NULL
     */
    protected function printLoadTimer() : ?string
    {
        $diffTimestamp = time();
        if ($diffTimestamp > ($this->timestamp + 1)){
            $loadingOutput = ".";
            $this->timestamp = $diffTimestamp;
        } else {
            $loadingOutput = null;
        }
        $this->emptyBuffer();
        return $loadingOutput;
    }
    
    /**
     * 
     * @return string
     */
    public function getInstallationResult() : string
    {
        return $this->installationStatus;
    }
    
    /**
     * 
     * @param bool $isSuccessful
     */
    protected function setInstallationResult(bool $isSuccessful) 
    {
        if($isSuccessful){
            $this->installationStatus = "Success";
        } else {
            $this->installationStatus = "Failure";
        }
    }
    
    /**
     * 
     */
    protected function emptyBuffer()
    {
        ob_flush();
        flush();
    }
    
    /**
     * 
     * @return string
     */
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
}