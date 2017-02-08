<?php

namespace FrontMatterObjectTests;

use allejo\stakx\Exception\InvalidSyntaxException;
use allejo\stakx\Object\ContentItem;

class FrontMatterFormatTest extends \PHPUnit_Stakx_TestCase
{
    public function testBlankLineAfterFrontMatter ()
    {
        $expectedFormat = <<<LINE
---
foo: bar
---

Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testNoBlankLineAfterFrontMatter ()
    {
        $expectedFormat = <<<LINE
---
foo: bar
---
Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testEmptyFrontMatter ()
    {
        $expectedFormat = <<<LINE
---
---

Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testEmptyWithLineFrontMatter ()
    {
        $expectedFormat = <<<LINE
---


---

Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testMultipleBlankLinesAfterFrontMatter ()
    {
        $expectedFormat = <<<LINE
---
foo: bar
---


Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testNoNewLineAfterFrontMatterThrowsException ()
    {
        $this->setExpectedException(InvalidSyntaxException::class);
        $expectedFormat = <<<LINE
---
foo: bar
---Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testNoNewLineAtBeginningOfFrontMatterThrowsException ()
    {
        $this->setExpectedException(InvalidSyntaxException::class);
        $expectedFormat = <<<LINE
---foo: bar
---

Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testFrontMatterInOneLineThrowsException ()
    {
        $this->setExpectedException(InvalidSyntaxException::class);
        $expectedFormat = <<<LINE
---foo: bar---

Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testNoNewLineBeforeFrontMatterClosesThrowsException ()
    {
        $this->setExpectedException(InvalidSyntaxException::class);
        $expectedFormat = <<<LINE
---
foo: bar---

Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testNoBodyThrowsException ()
    {
        $this->setExpectedException(InvalidSyntaxException::class);
        $expectedFormat = <<<LINE
---
foo: bar---
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testWhiteSpaceBodyThrowsException ()
    {
        $this->setExpectedException(InvalidSyntaxException::class);
        $expectedFormat = <<<LINE
---
foo: bar---


LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    public function testEmptyFrontMatterOneLineThrowsException ()
    {
        $this->setExpectedException(InvalidSyntaxException::class);
        $expectedFormat = <<<LINE
------

Some content
LINE;

        $this->createFile(ContentItem::class, $expectedFormat);
    }

    private function createFile ($classType, $content)
    {
        $this->dummyFile
            ->setContent($content)
            ->at($this->rootDir);

        return (new $classType($this->dummyFile->url()));
    }
}