<?php

namespace allejo\stakx\Manager;

use allejo\stakx\Exception\TrackedItemNotFoundException;
use allejo\stakx\Object\ContentItem;
use allejo\stakx\Object\PageView;
use allejo\stakx\System\Folder;
use Twig_Error_Syntax;
use Twig_Template;

/**
 * This class is responsible for handling all of the PageViews within a website.
 *
 * PageManager will parse all available dynamic and static PageViews. After, dynamic PageViews will be prepared by
 * setting the appropriate values for each ContentItem such as permalinks. Lastly, this class will compile all of the
 * PageViews and write them to the target directory.
 *
 * @package allejo\stakx\Manager
 */
class PageManager extends TrackingManager
{
    /**
     * @var ContentItem[][]
     */
    private $collections;

    /**
     * @var Folder
     */
    private $targetDir;

    private $siteMenu;

    /**
     * @var \Twig_Environment
     */
    private $twig;

    /**
     * PageManager constructor
     */
    public function __construct()
    {
        parent::__construct();
    }

    public function setCollections (&$collections)
    {
        if (empty($collections)) { return; }

        $this->collections = &$collections;
    }

    /**
     * @param Folder $folder The relative target directory as specified from the configuration file
     */
    public function setTargetFolder (&$folder)
    {
        $this->targetDir = &$folder;
    }

    public function configureTwig ($configuration, $options)
    {
        $twig = new TwigManager();
        $twig->configureTwig($configuration, $options);

        $this->twig = TwigManager::getInstance();
    }

    /**
     * An array representing the website's menu structure with children and grandchildren made from static PageViews
     *
     * @return array
     */
    public function getSiteMenu ()
    {
        return $this->siteMenu;
    }

    /**
     * Go through all of the PageView directories and create a respective PageView for each and classify them as a
     * dynamic or static PageView.
     *
     * @param $pageViewFolders
     */
    public function parsePageViews ($pageViewFolders)
    {
        if (empty($pageViewFolders)) { return; }

        /**
         * The name of the folder where PageViews are located
         *
         * @var $pageViewFolder string
         */
        foreach ($pageViewFolders as $pageViewFolderName)
        {
            $pageViewFolder = $this->fs->absolutePath($pageViewFolderName);

            if (!$this->fs->exists($pageViewFolder))
            {
                continue;
            }

            // @TODO Replace this with a regular expression or have wildcard support
            $this->scanTrackableItems($pageViewFolder, array(
                'refresh' => false
            ), array('.html', '.twig'));
        }
    }

    /**
     * Compile dynamic and static PageViews
     */
    public function compileAll ()
    {
        foreach (array_keys($this->trackedItemsFlattened) as $filePath)
        {
            $this->compileFromFilePath($filePath);
        }
    }

    public function compileSome ($filter = array())
    {
        /** @var PageView $pageView */
        foreach ($this->trackedItemsFlattened as $pageView)
        {
            if ($pageView->hasTwigDependency($filter['namespace'], $filter['dependency']))
            {
                $this->compilePageView($pageView);
            }
        }
    }

    /**
     * @param ContentItem $contentItem
     */
    public function compileContentItem (&$contentItem)
    {
        $pageView = $contentItem->getPageView();

        // This ContentItem doesn't have an individual PageView dedicated to displaying this item
        if (is_null($pageView))
        {
            return;
        }

        $template = $this->createTemplate($pageView);
        $contentItem->evaluateFrontMatter(
            $pageView->getFrontMatter(false)
        );

        $output = $template->render(array(
            'this' => $contentItem
        ));

        $this->targetDir->writeFile($contentItem->getTargetFile(), $output);
    }

    /**
     * Update an existing Twig variable that's injected globally
     *
     * @param string $variable
     * @param string $value
     */
    public function updateTwigVariable ($variable, $value)
    {
        $this->twig->addGlobal($variable, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function refreshItem($filePath)
    {
        $this->compileFromFilePath($filePath, true);
    }

    /**
     * {@inheritdoc}
     */
    protected function handleTrackableItem($filePath, $options = array())
    {
        $pageView = new PageView($filePath);
        $namespace = 'static';

        if ($pageView->isDynamicPage())
        {
            $namespace = 'dynamic';
            $frontMatter = $pageView->getFrontMatter(false);
            $collection = $frontMatter['collection'];

            foreach ($this->collections[$collection] as &$item)
            {
                $item->evaluateFrontMatter($frontMatter);
                $pageView->addContentItem($item);
            }
        }

        $this->addObjectToTracker($pageView, $pageView->getRelativeFilePath(), $namespace);
        $this->saveTrackerOptions($pageView->getRelativeFilePath(), array(
            'viewType' => $namespace
        ));

        if ($namespace === 'static')
        {
            $this->addToSiteMenu($pageView->getFrontMatter());
        }
    }

    /**
     * Compile a given PageView
     *
     * @param string $filePath The file path to the PageView to compile
     * @param bool   $refresh  When set to true, the PageView will reread its contents
     *
     * @throws \Exception
     */
    private function compileFromFilePath ($filePath, $refresh = false)
    {
        if (!$this->isTracked($filePath))
        {
            throw new TrackedItemNotFoundException('PageView not found');
        }

        /** @var PageView $pageView */
        $pageView = &$this->trackedItemsFlattened[$filePath];

        $this->compilePageView($pageView, $refresh);
    }

    /**
     * @param PageView $pageView
     * @param bool     $refresh
     */
    private function compilePageView ($pageView, $refresh = false)
    {
        if ($refresh)
        {
            $pageView->refreshFileContent();
        }

        if ($pageView->isDynamicPage())
        {
            $this->compileDynamicPageView($pageView);
        }
        else
        {
            $this->compileStaticPageView($pageView);
        }
    }

    /**
     * @param PageView $pageView
     */
    private function compileDynamicPageView (&$pageView)
    {
        $template = $this->createTemplate($pageView);

        $pageViewFrontMatter = $pageView->getFrontMatter(false);
        $collection = $pageViewFrontMatter['collection'];

        /** @var ContentItem $contentItem */
        foreach ($this->collections[$collection] as &$contentItem)
        {
            $output = $template->render(array(
                'this' => $contentItem
            ));

            $this->output->notice("Writing file: {file}", array('file' => $contentItem->getTargetFile()));
            $this->targetDir->writeFile($contentItem->getTargetFile(), $output);
        }
    }

    /**
     * @param PageView $pageView
     */
    private function compileStaticPageView (&$pageView)
    {
        $this->twig->addGlobal('__currentTemplate', $pageView->getFilePath());

        $template = $this->createTemplate($pageView);
        $output = $template->render(array(
            'this' => $pageView->getFrontMatter()
        ));

        $this->output->notice("Writing file: {file}", array('file' => $pageView->getTargetFile()));
        $this->targetDir->writeFile($pageView->getTargetFile(), $output);
    }

    /**
     * Add a static PageView to the menu array. Dynamic PageViews are not added to the menu
     *
     * @param array $frontMatter
     */
    private function addToSiteMenu ($frontMatter)
    {
        if (!array_key_exists('permalink', $frontMatter) ||
            (array_key_exists('menu', $frontMatter) && !$frontMatter['menu']))
        {
            return;
        }

        $url = $frontMatter['permalink'];
        $root = &$this->siteMenu;
        $permalink = trim($url, DIRECTORY_SEPARATOR);
        $dirs = explode(DIRECTORY_SEPARATOR, $permalink);

        while (count($dirs) > 0)
        {
            $name = array_shift($dirs);
            $name = (!empty($name)) ? $name : '.';

            if (!isset($root[$name]) && !is_null($name) && count($dirs) == 0)
            {
                $link = (pathinfo($url, PATHINFO_EXTENSION) !== "") ? $url : $permalink . DIRECTORY_SEPARATOR;

                $root[$name] = array_merge($frontMatter, array(
                    "url"  => '/' . $link,
                    "children" => array()
                ));
            }

            $root = &$root[$name]['children'];
        }
    }

    /**
     * @param PageView $pageView
     *
     * @return Twig_Template
     * @throws Twig_Error_Syntax
     */
    private function createTemplate ($pageView)
    {
        try
        {
            return $this->twig->createTemplate($pageView->getContent());
        }
        catch (Twig_Error_Syntax $e)
        {
            $e->setTemplateFile($pageView->getRelativeFilePath());

            throw $e;
        }
    }
}