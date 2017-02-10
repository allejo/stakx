<?php

namespace allejo\stakx\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Exception\IOException;

class BuildCommand extends BuildableCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure ()
    {
        parent::configure();

        $this->setName('build');
        $this->setDescription('Builds the stakx website');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute (InputInterface $input, OutputInterface $output)
    {
        parent::execute($input, $output);

        try
        {
            $this->configureBuild($input);
            $this->website->build();

            $output->writeln(sprintf("Your site built successfully! It can be found at: %s",
                $this->website->getConfiguration()->getTargetFolder() . DIRECTORY_SEPARATOR
            ));
        }
        catch (IOException $e)
        {
            $output->writeln(sprintf("Your website failed to build with the following error in file '%s': %s",
                $e->getPath(),
                $e->getMessage()
            ));
        }
        catch (\Exception $e)
        {
            $output->writeln(sprintf("Your website failed to build with the following error: %s",
                $e->getMessage()
            ));
        }
    }
}