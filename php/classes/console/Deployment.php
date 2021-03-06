<?php
/**
 * Images manipulation utilities class
 *
 * @package    Deployment
 * @author     Romain Laneuville <romain.laneuville@hotmail.fr>
 */

namespace classes\console;

use interfaces\FileManagerInterface as FileManager;
use classes\fileManager\FtpFileManager as FtpFileManager;
use classes\fileManager\SftpFileManager as SftpFileManager;
use classes\IniManager as Ini;
use \traits\EchoTrait as EchoTrait;
use \traits\DateTrait as DateTrait;

/**
 * Deployment class to deploy the application on a server using several protocol
 */
class Deployment extends Console
{
    use EchoTrait;
    use DateTrait;

    /**
     * @var        string[]  $SELF_COMMANDS     List of all commands with their description
     */
    private static $SELF_COMMANDS = array(
        'protocol [-p protocol] [--list|set]'               => 'Get all the available deployment protocols or get/set the protocol',
        'deploy [--static|php] [--skip-gulp]'               => 'Deploy the static or the php server or both (DEFAULT)',
        'configuration [-p param -v value] [--print|save]'  => 'Display or set deployment parameter (--save to save it in conf.ini'
    );
    /**
     * @var        string[]  $PROTOCOLS     List of available protocol
     */
    private static $PROTOCOLS = array(
        'FTP',
        'SFTP'
    );
    /**
     * @var        array  $PROJECT_MAIN_STRUCTURE   The project directories tree
     */
    private static $PROJECT_MAIN_STRUCTURE = array(
        '.' => array(
            'static' => array(
                'dist' => true,
                'html' => true
            ),
            'php' => array(
                'abstracts'   => true,
                'classes'     => array(
                    'console'            => true,
                    'entities'           => true,
                    'entitiesCollection' => true,
                    'entitiesManager'    => true,
                    'fileManager'        => true,
                    'logger'             => true,
                    'managers'           => true,
                    'websocket'          => array(
                        'services' => true
                    )
                ),
                'controllers' => true,
                'database'    => array(
                    'entities' => true
                ),
                'interfaces'  => array(
                    'http' => true
                ),
                'traits'      => true
            )
        )
    );
    /**
     * @var        string[]  $IGNORED_FILES     A list of files to not upload on the server
     */
    private static $IGNORED_FILES = array(
        'launchDeployment.php',
        'conf.ini',
        'conf-example.ini',
        'phpdoc.dist.xml',
        'log.txt',
        'app.js.map',
        'gulpfile.js',
        'package.json',
        'bower.json',
        'jsdocConfig.json',
        '.bowerrc',
        '.jscsrc',
        '.jshintrc',
        '.git',
        '.gitignore',
        '.gitattributes',
        'composer.lock',
        'devDoc.md',
        'README.md',
        'sonar-project.properties',
        'LICENSE'
    );

    /**
     * @var        string[]  $deploymentConfiguration   All the deployment configuration
     */
    private $deploymentConfiguration = array();
    /**
     * @var        string  $absoluteProjectRootPath     The absolute project root path
     */
    private $absoluteProjectRootPath;
    /**
     * @var        int   $timeOffset    Local timezone difference with UTC (to well compared the last modification file
     *                   date)
     */
    private $timeOffset;

    /**
     * Call the parent constructor, merge the commands list and launch the console
     */
    public function __construct()
    {
        parent::__construct();
        parent::$COMMANDS = array_merge(parent::$COMMANDS, static::$SELF_COMMANDS);

        $this->deploymentConfiguration = Ini::getSectionParams('Deployment');
        $this->absoluteProjectRootPath = dirname(__FILE__, 5);
        $this->timeOffset              = static::getTimezoneOffset('Greenwich');

        static::$PROJECT_MAIN_STRUCTURE[
            $this->deploymentConfiguration['remoteProjectRootDirectoryName']
        ] = static::$PROJECT_MAIN_STRUCTURE['.'];

        unset(static::$PROJECT_MAIN_STRUCTURE['.']);

        static::out(PHP_EOL . 'Absolute project root path is "' . $this->absoluteProjectRootPath . '"' . PHP_EOL);

        $this->launchConsole();
    }

    /**
     * Process the command entered by the user and output the result in the console
     *
     * @param      string  $command   The command passed by the user
     * @param      bool    $executed  True if the command is already executed, false otherwise DEFAULT false
     */
    protected function processCommand(string $command, bool $executed = false)
    {
        $executed = true;

        preg_match('/^[a-zA-Z ]*/', $command, $commandName);

        static::out(PHP_EOL);

        switch (rtrim($commandName[0])) {
            case 'protocol':
                $this->protocolProcess($command);
                break;

            case 'deploy':
                $this->deployProcess($command);
                break;

            case 'configuration':
                $this->configurationProcess($command);
                break;

            default:
                $executed = false;
                break;
        }

        parent::processCommand($command, $executed);
    }

    /**
     * Process the command called on the protocol
     *
     * @param      string  $command  The command passed with its arguments
     */
    private function protocolProcess(string $command)
    {
        $args = $this->getArgs($command);

        if (isset($args['list'])) {
            static::out($this->tablePrettyPrint(static::$PROTOCOLS) . PHP_EOL);
        } elseif (isset($args['set'])) {
            if (in_array($args['p'], static::$PROTOCOLS)) {
                $this->deploymentConfiguration['protocol'] = $args['p'];
                static::out('Protocol is now "' . $args['p'] . '"' . PHP_EOL);
            } else {
                static::out('Protocol "' . $args['p'] . '" is not supported' . PHP_EOL);
            }
        } else {
            static::out('The current protocol is "' . $this->deploymentConfiguration['protocol'] . '"' . PHP_EOL);
        }
    }

    /**
     * Launch the deployment of the website or the websocket server or both
     *
     * @param      string  $command  The command passed with its arguments
     */
    private function deployProcess(string $command)
    {
        $args     = $this->getArgs($command);
        $skipGulp = isset($args['skip-gulp']);

        try {
            if (isset($args['static'])) {
                $this->deployStatic($skipGulp);
            } elseif (isset($args['php'])) {
                $this->deployPhp($skipGulp);
            } else {
                $this->deployStatic($skipGulp);
                $this->deployPhp($skipGulp);
            }
        } catch (\Exception $e) {
            static::fail($e->getMessage());
        }
    }

    /**
     * Display or set deployment configuration parameters
     *
     * @param      string  $command  The command passed with its arguments
     */
    private function configurationProcess(string $command)
    {
        $args = $this->getArgs($command);

        if (isset($args['print'])) {
            if (isset($args['p'])) {
                $this->setProtocol($args['p']);
            } else {
                $this->printDeploymentInformation();
            }
        } else {
            if (isset($args['p']) && isset($args['v'])) {
                if (array_key_exists($args['p'], $this->deploymentConfiguration)) {
                    if ($args['p'] === 'protocol') {
                        $this->setProtocol($args['v']);
                    } else {
                        $this->deploymentConfiguration[$args['p']] = $args['v'];
                        static::out($args['p'] . ' = ' . $args['v'] . PHP_EOL);
                    }

                    if (isset($args['save'])) {
                        // @todo Ini::setParam('Deployment', $args['p'], $args['v']);
                    }
                } else {
                    static::out('The parameter "' . $args['p'] . '" does not exist' . PHP_EOL);
                }
            } else {
                static::out('You must specify parameters p and v with -p parameter and -v value' . PHP_EOL);
            }
        }
    }

    /**
     * Deploy the php server on the remote server
     *
     * @param      bool  $skipGulp  True to skip gulp process else false DEFAULT false
     */
    private function deployPhp(bool $skipGulp = false)
    {
        if (!$skipGulp) {
            $this->gulpPreProcessingPhp();
        }

        $directoriesTree = static::$PROJECT_MAIN_STRUCTURE;
        unset($directoriesTree[$this->deploymentConfiguration['remoteProjectRootDirectoryName']]['static']);

        static::out(PHP_EOL . 'Uploading PHP files ...' . PHP_EOL);

        $this->deploy($directoriesTree);
        $this->composerInstall();

        static::ok('PHP deployment completed' . PHP_EOL);
    }

    /**
     * Deploy the static repo (js and css) on the remote server
     *
     * @param      bool  $skipGulp  True to skip gulp process else false DEFAULT false
     */
    private function deployStatic(bool $skipGulp = false)
    {
        if (!$skipGulp) {
            $this->gulpPreProcessingStatic();
        }

        $directoriesTree = static::$PROJECT_MAIN_STRUCTURE;
        unset($directoriesTree[$this->deploymentConfiguration['remoteProjectRootDirectoryName']]['php']);

        static::out(PHP_EOL . 'Uploading static files ...' . PHP_EOL);

        $this->deploy($directoriesTree);

        static::ok('Static deployment completed' . PHP_EOL);
    }

    /**
     * Deploy the directories tree passed in argument to the remote server
     *
     * @param      array  $directoriesTree  The directories tree to deploy
     */
    private function deploy(array $directoriesTree)
    {
        $skip        = false;
        $fileManager = null;

        switch ($this->deploymentConfiguration['protocol']) {
            case 'FTP':
                $fileManager = new FtpFileManager($this->deploymentConfiguration);
                break;

            case 'SFTP':
                $fileManager = new SftpFileManager($this->deploymentConfiguration);
                break;

            default:
                static::out(
                    'The protocol "' . $this->deploymentConfiguration['protocol'] . '" is not implemented' . PHP_EOL
                );

                $skip = true;
        }

        if (!$skip) {
            // Connect, login and cd on the project directory container
            $fileManager->connect();
            $fileManager->login();
            $fileManager->changeDir($this->deploymentConfiguration['remoteProjectRootDirectory']);

            // Create the project directory root if it does not exist
            $fileManager->makeDirIfNotExists($this->deploymentConfiguration['remoteProjectRootDirectoryName']);

            // Create main directories structure if it does not exist
            $this->createMainProjectStructureRecursive(
                $fileManager,
                $this->deploymentConfiguration['remoteProjectRootDirectoryName'],
                $directoriesTree
            );

            // Upload files if the last modification date on local is greater than remote
            $this->uploadFilesRecursive(
                $fileManager,
                $this->deploymentConfiguration['remoteProjectRootDirectoryName'],
                $directoriesTree,
                $this->absoluteProjectRootPath
            );
        }
    }

    /**
     * Create a directories tree recursively
     *
     * @param      FileManager  $fileManager       A FileManager class that implements FileManagerInterface
     * @param      string       $workingDirectory  The directory to create the current depth structure
     * @param      array        $arrayDepth        The tree of the current depth structure
     */
    private function createMainProjectStructureRecursive(
        $fileManager,
        string $workingDirectory,
        array $arrayDepth
    ) {
        $fileManager->changeDir($workingDirectory);

        foreach ($arrayDepth[$workingDirectory] as $directoryName => $subDir) {
            $fileManager->makeDirIfNotExists($directoryName);

            if (is_array($subDir)) {
                $this->createMainProjectStructureRecursive($fileManager, $directoryName, $arrayDepth[$workingDirectory]);
            }
        }

        $fileManager->changeDir('..');
    }

    /**
     * Upload files recursively on server if the local last modification date is greatest than on the remote
     *
     * @param      FileManager  $fileManager            A FileManager class that implements FileManagerInterface
     * @param      string       $workingDirectory       The directory to create the current depth structure
     * @param      array        $arrayDepth             The tree of the current depth structure
     * @param      string       $localWorkingDirectory  The current local working directory
     */
    private function uploadFilesRecursive(
        $fileManager,
        string $workingDirectory,
        array $arrayDepth,
        string $localWorkingDirectory
    ) {
        $fileManager->changeDir($workingDirectory);
        $localWorkingDirectory .= DIRECTORY_SEPARATOR . $workingDirectory;
        $currentDirectory = new \DirectoryIterator($localWorkingDirectory);

        foreach ($currentDirectory as $fileInfo) {
            if (!$fileInfo->isDot()) {
                if ($fileInfo->isFile() &&
                    $fileInfo->getMTime() > $fileManager->lastModified($fileInfo->getFilename()) - $this->timeOffset
                ) {
                    if (!in_array($fileInfo->getFilename(), static::$IGNORED_FILES)) {
                        $fileManager->upload($fileInfo->getFilename(), $fileInfo->getPathname());
                    } elseif ((int) $this->deploymentConfiguration['verbose'] > 1) {
                        static::out($fileInfo->getPathname() . ' is ignored' . PHP_EOL);
                    }
                }

                if ($fileInfo->isDir() && isset($arrayDepth[$workingDirectory][$fileInfo->getFilename()])) {
                    $this->uploadFilesRecursive(
                        $fileManager,
                        $fileInfo->getFilename(),
                        $arrayDepth[$workingDirectory],
                        $localWorkingDirectory
                    );
                }
            }
        }

        $fileManager->changeDir('..');
    }

    /**
     * Output the deployment configuration
     */
    private function printDeploymentInformation()
    {
        static::out($this->tableAssociativePrettyPrint($this->deploymentConfiguration) . PHP_EOL);
    }

    /**
     * Set the protocol
     *
     * @param      string  $value  The protocol to set
     */
    private function setProtocol(string $value)
    {
        if (in_array($value, static::$PROTOCOLS)) {
            $this->deploymentConfiguration['protocol'] = $value;
            static::ok('Protocol is now "' . $value . '"' . PHP_EOL);
        } else {
            static::fail(
                'Protocol "' . $value . '" is not supported, type protocol --list to see supported protocols' . PHP_EOL
            );
        }
    }

    /**
     * Install composer dependencies on the remote server with --no-dev
     */
    private function composerInstall()
    {
        static::out(PHP_EOL . 'Installing composer dependencies on remote ...' . PHP_EOL . PHP_EOL);
        static::execWithPrintInLive(
            'ssh root@vps cd ' . $this->deploymentConfiguration['remoteProjectRootDirectory'] . '/' .
            $this->deploymentConfiguration['remoteProjectRootDirectoryName'] . '/php; composer install -o --no-dev'
        );
    }

    /**
     * Pre-processing js/less files before deployment and generate doc
     */
    private function gulpPreProcessingStatic()
    {
        static::out(PHP_EOL . 'Prepare the static deployment, running gulp tasks ...' . PHP_EOL . PHP_EOL);
        static::execWithPrintInLive('cd ../static & gulp deploy_static');
    }

    /**
     * Pre-processing php files before deployment and generate doc
     */
    private function gulpPreProcessingPhp()
    {
        static::out(PHP_EOL . 'Prepare the PHP deployment, running gulp tasks ...' . PHP_EOL . PHP_EOL);
        static::execWithPrintInLive('cd ../static & gulp deploy_php');
    }
}
