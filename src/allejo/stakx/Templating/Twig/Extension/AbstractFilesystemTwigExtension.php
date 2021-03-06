<?php

/**
 * @copyright 2018 Vladimir Jimenez
 * @license   https://github.com/stakx-io/stakx/blob/master/LICENSE.md MIT
 */

namespace allejo\stakx\Templating\Twig\Extension;

use allejo\stakx\Filesystem\FilesystemLoader as fs;
use allejo\stakx\Service;
use Symfony\Component\Filesystem\Exception\FileNotFoundException;

abstract class AbstractFilesystemTwigExtension extends AbstractTwigExtension
{
    protected $dir;
    protected $path;

    public function __invoke($location)
    {
        $this->dir = fs::getFolderPath(Service::getOption('currentTemplate'));
        $this->path = fs::appendPath($this->dir, $location);

        if (is_file($this->path))
        {
            $this->path = realpath($this->path);
        }

        if (strpos($this->path, Service::getWorkingDirectory()) !== 0)
        {
            throw new FileNotFoundException(sprintf(
                "The '%s' file could not be found or is outside the website working directory",
                $location
            ));
        }
    }
}
