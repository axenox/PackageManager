<?php
namespace axenox\PackageManager\Common\Updater;

use Symfony\Component\Process\Process;
use exface\Core\DataTypes\StringDataType;

class SelfUpdateInstaller {
    
    private $statusMessage = null;
    
    private $timestamp = null;
    
    private $installationSuccess = false;
    
    private $tmpFolderPath = null;
    
    private $installationFilePath = null;
    
    /**
     * 
     * @param string $installationFilePath
     * @param string $tmpFolderPath
     */
    public function __construct(string $installationFilePath, string $tmpFolderPath)
    {
        $this->tmpFolderPath = $tmpFolderPath;
        $this->installationFilePath = $installationFilePath;
    }

    /**
     * 
     * @return void|Generator
     */
    public function install()
    {
        $cmd = 'php -d memory_limit=2G ' . $this->installationFilePath;
        yield "Installing " . end(explode("/", $this->installationFilePath)) . "..." .PHP_EOL .PHP_EOL;
        $envVars = ['COMPOSER_HOME' => $this->tmpFolderPath . DIRECTORY_SEPARATOR . '.composer'];
        /* @var $process \Symfony\Component\Process\Process */
        $process = Process::fromShellCommandline($cmd, null, $envVars, null, 600);
        $process->start();
        
        $this->timestamp = time();
        $outputBuffer = [];
        $msgBuffer = '';
        foreach ($process as $msg) {
            while ($process->isRunning()) {
                yield from $this->printLoadTimer($process->getOutput());
            }
            $msgBuffer .= $msg;
            // writing output for this command line disabled as it might confuse the user more than help him
            if (StringDataType::endsWith($msg, "\r", false) || StringDataType::endsWith($msg, "\n", false)) {
                yield $msgBuffer;
                yield "";
                $outputBuffer[] = $msgBuffer; 
                $msgBuffer = '';
            }
        }
        $this->setInstallationSuccess($process->IsSuccessful());
        if ($process->isSuccessful() === false) {
            yield 'Creating base composer.lock file failed, can not install packages!' . PHP_EOL;
            yield 'See the following error messages for more information.' . PHP_EOL;
            yield $this->printLineDelimiter();
            foreach ($outputBuffer as $output) {
                yield $output;
            }
            yield $this->printLineDelimiter();
            yield "Installation failed!";
        } else {
            yield $this->printLineDelimiter();
            yield "Installation successful!";
        }
    }

    /**
     *
     * @return Generator
     */
    protected function printLoadTimer($output)
    {
        $diffTimestamp = time();
        if ($diffTimestamp > ($this->timestamp + 1) || $output !== $this->statusMessage) {
            yield ".";
            $this->timestamp = $diffTimestamp;
            $this->statusMessage = $output;
        }
    }

    /**
     *
     * @return string
     */
    public function getFormatedStatusMessage() : string
    {
        return $this->getInstallationSuccess() == 1 ? "Success" : "Failure";
    }

    /**
     * 
     * @return string
     */
    public function getInstallationSuccess() : bool
    {
        return $this->installationSuccess;
    }
    
    /**
     * 
     * @param bool $isSuccessful
     */
    protected function setInstallationSuccess(bool $isSuccessful)
    {
        $this->installationSuccess = $isSuccessful;
    }

    /**
     * 
     * @return string
     */
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
    
    /**
     * empties output buffer for real-time output
     */
    protected function emptyBuffer()
    {
        ob_flush();
        flush();
    }
}