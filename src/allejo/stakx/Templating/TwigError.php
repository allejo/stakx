<?php

namespace allejo\stakx\Templating;

class TwigError extends \Exception implements TemplateErrorInterface
{
    private $error;
    private $content;
    private $relativeFilePath;
    private $name;

    public function __construct(\Twig_Error $error)
    {
        $this->error = $error;
    }

    /**
     * {@inheritdoc}
     */
    public function getTemplateLine()
    {
        return $this->error->getTemplateLine();
    }

    /**
     * {@inheritdoc}
     */
    public function setTemplateLine($lineNumber)
    {
        $this->error->setTemplateLine($lineNumber);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setContent($content)
    {
        $this->content = $content;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setName($name)
    {
        $this->name = $name;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function setRelativeFilePath($filePath)
    {
        $this->relativeFilePath = $filePath;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function buildException()
    {
        $this->error->setSourceContext(new \Twig_Source(
            $this->content,
            $this->name,
            $this->relativeFilePath
        ));
    }
}