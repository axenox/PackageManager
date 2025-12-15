<?php
namespace axenox\PackageManager\Common\Updater;

use Symfony\Component\Process\Process;
use exface\Core\DataTypes\StringDataType;

class SelfUpdateInstaller {
    
    const MESSAGE_INSTALLATION_FAILED = 'Installation failed!';
    const MESSAGE_INSTALLATION_SUCCESSFUL = 'Installation successful!';
    
    private $statusMessage = null;
    
    private $timeStamp = null;
    
    private $installationSuccess = false;
    
    private $tmpFolderPath = null;
    
    private $installationFilePath = null;
    
    private string $phpExecutable = 'php';
    
    /**
     * 
     * @param string $installationFilePath
     * @param string $tmpFolderPath
     * @param string $phpExecutable
     */
    public function __construct(string $installationFilePath, string $tmpFolderPath, string $phpExecutable = 'php')
    {
        $this->tmpFolderPath = $tmpFolderPath;
        $this->installationFilePath = $installationFilePath;
        $this->phpExecutable = $phpExecutable;
    }

    /**
     * 
     * @return void|Generator
     */
    public function install()
    {
        $cmd = $this->phpExecutable . ' -d memory_limit=2G ' . $this->installationFilePath;
        $pathArr = explode("/", $this->installationFilePath);
        $phxName = end($pathArr);
        
        // Make sure composer is runnable by adding required environment variables
        $envVars = ['COMPOSER_HOME' => $this->tmpFolderPath . DIRECTORY_SEPARATOR . '.composer'];
        
        yield "Extracting " . $phxName . "..." .PHP_EOL .PHP_EOL;
        /* @var $process \Symfony\Component\Process\Process */
        $process = Process::fromShellCommandline($cmd, null, $envVars, null, 600);
        $process->start();
        
        $this->timeStamp = time();
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
            yield self::MESSAGE_INSTALLATION_FAILED;
        } else {
            yield $this->printLineDelimiter();
            yield self::MESSAGE_INSTALLATION_SUCCESSFUL;
        }
    }

    /**
     *
     * @return Generator
     */
    protected function printLoadTimer($output)
    {
        $diffTimestamp = time();
        if ($diffTimestamp > ($this->timeStamp + 1) || $output !== $this->statusMessage) {
            yield ".";
            $this->timeStamp = $diffTimestamp;
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
     * @return int
     */
    public function getTimestamp() : int
    {
        return $this->timeStamp;
    }

    /**
     * 
     * @return bool
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
     * Prints NEWLINE ------------------------ NEWLINE
     * @return string
     */
    protected function printLineDelimiter() : string
    {
        return PHP_EOL . '--------------------------------' . PHP_EOL . PHP_EOL;
    }
}