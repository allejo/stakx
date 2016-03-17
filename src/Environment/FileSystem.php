<?php

namespace allejo\stakx\Environment;

class Filesystem extends \Symfony\Component\Filesystem\Filesystem
{
    /**
     * @return string
     */
    public function buildPath ()
    {
        return implode(DIRECTORY_SEPARATOR, func_get_args());
    }

    public function getFileName ($filePath)
    {
        return pathinfo($filePath, PATHINFO_BASENAME);
    }

    public function getFolderPath ($filePath)
    {
        return pathinfo($filePath, PATHINFO_DIRNAME);
    }

    /**
     * @param  string $filename A filename
     *
     * @return string The extension of the file
     */
    public function getExtension ($filename)
    {
        return pathinfo($filename, PATHINFO_EXTENSION);
    }

    /**
     * Finds path, relative to the given root folder, of all files and directories in the given directory and its
     * sub-directories non recursively.
     *
     * @author sreekumar
     *
     * @param  string $root   The location where to search
     * @param  array  $ignore An array of folders to ignore
     *
     * @return array A nested array with two associative keys, 'files' & 'dirs'
     */
    public function ls ($root = '.', $ignore = array('.', '..'))
    {
        $files  = array('files' => array(), 'dirs' => array());
        $directories  = array();
        $last_letter  = $root[strlen($root) - 1];
        $root  = ($last_letter == '\\' || $last_letter == '/') ? $root : $root . DIRECTORY_SEPARATOR;

        $directories[]  = $root;

        while (sizeof($directories))
        {
            $dir  = array_pop($directories);

            if ($handle = opendir($dir))
            {
                while (false !== ($file = readdir($handle)))
                {
                    if (in_array($file, $ignore))
                    {
                        continue;
                    }

                    $file  = $dir.$file;

                    if (is_dir($file))
                    {
                        $directory_path = $file . DIRECTORY_SEPARATOR;
                        array_push($directories, $directory_path);
                        $files['dirs'][]  = $directory_path;
                    }
                    elseif (is_file($file))
                    {
                        $files['files'][]  = $file;
                    }
                }

                closedir($handle);
            }
        }

        return $files;
    }

    public function writeFile ($targetDir, $fileName, $content)
    {
        $outputFolder = $this->getFolderPath($this->buildPath($targetDir, $fileName));
        $targetFile   = $this->getFileName($fileName);

        if (!file_exists($outputFolder))
        {
            mkdir($outputFolder, 0755, true);
        }

        file_put_contents($this->buildPath($outputFolder, $targetFile), $content, LOCK_EX);
    }
}