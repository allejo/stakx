<?php

namespace allejo\stakx\Object;

use allejo\stakx\Core\ConsoleInterface;
use allejo\stakx\Engines\TwigMarkdownEngine;
use allejo\stakx\Manager\PageManager;
use allejo\stakx\System\Filesystem;
use allejo\stakx\Manager\CollectionManager;
use allejo\stakx\Manager\DataManager;
use allejo\stakx\System\Folder;
use allejo\stakx\Twig\FilesystemExtension;
use allejo\stakx\Twig\TwigExtension;
use Aptoma\Twig\Extension\MarkdownExtension;
use JasonLewis\ResourceWatcher\Tracker;
use JasonLewis\ResourceWatcher\Watcher;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;
use Twig_Environment;
use Twig_Loader_Filesystem;

class Website
{
    private static $twig_ref;

    /**
     * The Twig environment that will be used to render pages. This includes all of the loaded extensions and global
     * variables.
     *
     * @var Twig_Environment
     */
    private $twig;

    /**
     * The location of where the compiled website will be written to
     *
     * @var Folder
     */
    private $outputDirectory;

    /**
     * The main configuration to be used to build the specified website
     *
     * @var Configuration
     */
    private $configuration;

    /**
     * When set to true, the Stakx website will be built without a configuration file
     *
     * @var bool
     */
    private $confLess;

    /**
     * When set to true, Twig templates will not have access to filters or functions which provide access to the
     * filesystem
     *
     * @var bool
     */
    private $safeMode;

    /**
     * @var ConsoleInterface
     */
    private $output;

    /**
     * @var CollectionManager
     */
    private $cm;

    /**
     * @var DataManager
     */
    private $dm;

    /**
     * @var Filesystem
     */
    private $fs;

    /**
     * @var PageManager
     */
    private $pm;

    /**
     * Website constructor.
     *
     * @param OutputInterface $output
     */
    public function __construct (OutputInterface $output)
    {
        $this->output = new ConsoleInterface($output);
        $this->cm = new CollectionManager();
        $this->dm = new DataManager();
        $this->pm = new PageManager();
        $this->fs = new Filesystem();
    }

    /**
     * Compile the website.
     *
     * @param bool $cleanDirectory Clean the target directing before rebuilding
     */
    public function build ($cleanDirectory)
    {
        // Parse DataItems
        $this->dm->setConsoleOutput($this->output);
        $this->dm->parseDataItems($this->getConfiguration()->getDataFolders());
        $this->dm->parseDataSets($this->getConfiguration()->getDataSets());

        // Prepare Collections
        $this->cm->setConsoleOutput($this->output);
        $this->cm->parseCollections($this->getConfiguration()->getCollectionsFolders());

        // Handle PageViews
        $this->pm->setConsoleOutput($this->output);
        $this->pm->setTwig($this->twig);
        $this->pm->parsePageViews($this->getConfiguration()->getPageViewFolders());
        $this->pm->prepareDynamicPageViews($this->cm->getCollections());

        // Configure the environment
        $this->createFolderStructure($cleanDirectory);
        $this->configureTwig();

        // Our output directory
        $this->outputDirectory = new Folder($this->getConfiguration()->getTargetFolder());
        $this->outputDirectory->setTargetDirectory($this->getConfiguration()->getBaseUrl());

        // Copy over assets
        $this->output->notice('Copying theme assets...');
        $this->copyThemeAssets();

        $this->output->notice('Copying static files...');
        $this->copyStaticFiles();

        // Compile everything
        $this->output->notice('Compiling files...');
        $this->pm->compileAll(
            $this->outputDirectory
        );
    }

    public function watch ()
    {
        $this->build(true);

        $tracker    = new Tracker();
        $watcher    = new Watcher($tracker, $this->fs);
        $listener   = $watcher->watch(getcwd());
        $targetPath = $this->getConfiguration()->getTargetFolder();

        $this->output->notice('Watch started successfully');

        $listener->onModify(function ($resource, $path) use ($targetPath) {
            $filePath = $this->fs->getRelativePath($path);

            if ((substr($filePath, 0, strlen($targetPath)) === $targetPath) ||
                (substr($filePath, 0, 1) === '.'))
            {
                return;
            }

            $this->output->writeln(sprintf("File change detected: %s", $filePath));

            try
            {
                if ($this->pm->isPageView($filePath))
                {
                    $this->pm->compileSingle($filePath);
                }
                else if ($this->cm->isContentItem($filePath))
                {
                    $contentItem = &$this->cm->getContentItem($filePath);
                    $contentItem->refreshFileContent();

                    $this->pm->compileContentItem($contentItem);
                }
            }
            catch (\Exception $e)
            {
                $this->output->error(sprintf("Your website failed to build with the following error: %s",
                    $e->getMessage()
                ));
            }
        });

        $watcher->start();
    }

    /**
     * @return Configuration
     */
    public function getConfiguration ()
    {
        return $this->configuration;
    }

    /**
     * @param string $configFile
     *
     * @throws \LogicException
     */
    public function setConfiguration ($configFile)
    {
        if (!$this->fs->exists($configFile) && !$this->isConfLess())
        {
            $this->output->error("You are trying to build a website in a directory without a configuration file. Is this what you meant to do?");
            $this->output->error("To build a website without a configuration, use the '--no-conf' option");

            throw new \LogicException("Cannot build a website without a configuration when not in Configuration-less mode");
        }

        if ($this->isConfLess())
        {
            $configFile = "";
        }

        $this->configuration = new Configuration($configFile, $this->output);
    }

    /**
     * Get whether or not the website is being built in Configuration-less mode
     *
     * @return bool True when being built with no configuration file
     */
    public function isConfLess ()
    {
        return $this->confLess;
    }

    /**
     * Set whether or not the website should be built with a configuration
     *
     * @param bool $status True when a website should be built without a configuration
     */
    public function setConfLess ($status)
    {
        $this->confLess = $status;
    }

    /**
     * Get whether or not the website is being built in safe mode.
     *
     * Safe mode is defined as disabling file system access from Twig and disabling user Twig extensions
     *
     * @return bool True when the website is being built in safe mode
     */
    public function isSafeMode ()
    {
        return $this->safeMode;
    }

    /**
     * Set whether a website should be built in safe mode
     *
     * @param bool $bool True if a website should be built in safe mode
     */
    public function setSafeMode ($bool)
    {
        $this->safeMode = $bool;
    }

    public static function getTwigInstance ()
    {
        return self::$twig_ref;
    }

    /**
     * Prepare the Stakx environment by creating necessary cache folders
     *
     * @param bool $cleanDirectory Clean the target directory
     */
    private function createFolderStructure ($cleanDirectory)
    {
        $tarDir = $this->fs->absolutePath($this->configuration->getTargetFolder());

        if ($cleanDirectory)
        {
            $this->fs->remove($tarDir);
        }

        $this->fs->remove($this->fs->absolutePath('.stakx-cache'));
        $this->fs->mkdir('.stakx-cache/twig');
        $this->fs->mkdir($tarDir);
    }

    /**
     * Configure the Twig environment used by Stakx. This includes loading themes, global variables, extensions, and
     * debug settings.
     *
     * @todo Load custom Twig extensions from _config.yml
     */
    private function configureTwig ()
    {
        $loader   = new Twig_Loader_Filesystem(array(
            getcwd()
        ));
        $theme    = $this->configuration->getTheme();
        $mdEngine = new TwigMarkdownEngine();

        // Only load a theme if one is specified and actually exists
        if (!is_null($theme))
        {
            try
            {
                $loader->addPath($this->fs->absolutePath('_themes', $this->configuration->getTheme()), 'theme');
            }
            catch (\Twig_Error_Loader $e)
            {
                $this->output->error("The following theme could not be loaded: {theme}", array(
                    "theme" => $theme
                ));
                $this->output->error($e->getMessage());
            }
        }

        $this->twig = new Twig_Environment($loader, array(
            'autoescape' => $this->getConfiguration()->getTwigAutoescape(),
            //'cache'      => '.stakx-cache/twig'
        ));

        $this->twig->addGlobal('site', $this->configuration->getConfiguration());
        $this->twig->addGlobal('collections', $this->cm->getCollections());
        $this->twig->addGlobal('menu', $this->pm->getSiteMenu());
        $this->twig->addGlobal('data', $this->dm->getDataItems());
        $this->twig->addExtension(new TwigExtension());
        $this->twig->addExtension(new \Twig_Extensions_Extension_Text());
        $this->twig->addExtension(new MarkdownExtension($mdEngine));

        if (!$this->safeMode)
        {
            $this->twig->addExtension(new FilesystemExtension());
        }

        if ($this->configuration->isDebug())
        {
            $this->twig->addExtension(new \Twig_Extension_Debug());
            $this->twig->enableDebug();
        }

        self::$twig_ref = &$this->twig;
    }

    /**
     * Copy static files from a theme to the compiled website
     */
    private function copyThemeAssets ()
    {
        $theme = $this->configuration->getTheme();

        if (is_null($theme))
        {
            return;
        }

        $themeFolder = $this->fs->appendPath("_themes", $theme);
        $themeFile   = $this->fs->absolutePath($themeFolder, "stakx-theme.yml");
        $themeData   = array();

        if ($this->fs->exists($themeFile))
        {
            $themeData = Yaml::parse(file_get_contents($themeFile));
        }

        foreach ($themeData['include'] as &$include)
        {
            $include = $this->fs->appendPath($themeFolder, $include);
        }

        $finder = $this->fs->getFinder(
            array_merge(
                $this->getConfiguration()->getIncludes(),
                $themeData['include']
            ),
            array_merge(
                $this->getConfiguration()->getExcludes(),
                $themeData['exclude'],
                array('.twig')
            ),
            $this->fs->absolutePath($themeFolder)
        );

        /** @var SplFileInfo $file */
        foreach ($finder as $file)
        {
            $this->copyToCompiledSite($file, $themeFolder);
        }
    }

    /**
     * Copy the static files from the current directory into the compiled website directory.
     *
     * Static files are defined as follows:
     *   - Does not start with an underscore or is inside of a directory starting with an underscore
     *   - Does not start with a period or is inside of a directory starting with a period
     */
    private function copyStaticFiles ()
    {
        $finder = $this->fs->getFinder(
            $this->getConfiguration()->getIncludes(),
            $this->getConfiguration()->getExcludes()
        );

        /** @var $file SplFileInfo */
        foreach ($finder as $file)
        {
            $this->copyToCompiledSite($file);
        }
    }

    /**
     * Copy a file from a the source directory to the compiled website directory. The exact relative path to the file
     * will be recreated in the compiled website directory.
     *
     * @param SplFileInfo $file   The relative path of the file to be copied
     * @param string      $prefix
     */
    private function copyToCompiledSite ($file, $prefix = "")
    {
        if (!$this->fs->exists($file)) { return; }

        $filePath = $file->getRealPath();
        $pathToStrip = $this->fs->appendPath(getcwd(), $prefix);
        $siteTargetPath = ltrim(str_replace($pathToStrip, "", $filePath), DIRECTORY_SEPARATOR);

        try
        {
            $this->outputDirectory->copyFile($filePath, $siteTargetPath);
        }
        catch (\Exception $e)
        {
            $this->output->error($e->getMessage());
        }
    }
}