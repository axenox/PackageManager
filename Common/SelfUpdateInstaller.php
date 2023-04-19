<?php
namespace axenox\PackageManager\Common;

use Symfony\Component\Process\Process;

class SelfUpdateInstaller {
    
    private $statusMessage = null;
    
    private $timestamp = null;
    
    private $installationStatus = false;

        public function install(string $command, string $filePath)
        {
            $cmd = $command . " " . $filePath;
            yield "Installing " . end(explode("/", $filePath)) . "..." .PHP_EOL .PHP_EOL;
            /* @var $process \Symfony\Component\Process\Process */
            $process = Process::fromShellCommandline($cmd, null, null, null, 600);
            $process->start();
            $this->timestamp = time();
            while ($process->isRunning()) {
                if ($process->getOutput() !== $this->statusMessage) {
                    yield from $this->printProgress($process->getOutput());
                } else {
                    yield from $this->printLoadTimer();
                }
            }
            yield $this->printLineDelimiter();
            $this->setInstallationStatus($process->IsSuccessful());
            
            if($this->getInstallationStatus()) {
                yield "Installation successful!";
            } else {
                yield "Installation failed!";
            }
        }

    /**
     * 
     * @param string $output
     * @return Generator
     */
    protected function printProgress(string $output)
    {
        yield substr($output, strlen($this->statusMessage));
        $this->statusMessage = $output;
    }
    
    /**
     * 
     * @return Generator
     */
    protected function printLoadTimer()
    {
        $diffTimestamp = time();
        if ($diffTimestamp > ($this->timestamp + 1)) {
            yield ".";
            $this->timestamp = $diffTimestamp;
        }
    }
    
    /**
     * 
     * @return string
     */
    public function getInstallationStatus() : string
    {
        return $this->installationStatus;
    }
    
    /**
     * 
     * @param bool $isSuccessful
     */
    protected function setInstallationStatus(bool $isSuccessful) 
    {
        if($isSuccessful) {
            $this->installationStatus = "Success";
        } else {
            $this->installationStatus = "Failure";
        }
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