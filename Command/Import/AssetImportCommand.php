<?php

namespace Youwe\PimcoreAssetImporterBundle\Command\Import;

use Pimcore\Console\AbstractCommand;
use Pimcore\Model\Asset;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;

/**
 * Class AssetImportCommandInsert
 */
class AssetImportCommand extends AbstractCommand
{
    /**
     * @var Asset\Folder
     */
    protected $rootFolderAsset;

    /**
     * @var bool
     */
    protected $updateAssets = false;

    /**
     * @var int
     */
    protected $batchSize = 0;

    /**
     * @var bool
     */
    protected $deleteOriginal = false;

    /**
     * Configure asset import arguments and options
     */
    protected function configure()
    {
        $this
            ->setName('youwe:import:assets')
            ->setDescription('Import assets into Pimcore. Currently only supports directories and images. Original files are deleted.')
            ->addArgument(
                'path',
                InputOption::VALUE_REQUIRED,
                'Absolute path to directory containing assets'
            )->addOption(
                'rootPath',
                null,
                InputOption::VALUE_OPTIONAL,
                'Pimcore root path to asset folder to import the assets to. For example: /Images/'
            )->addOption(
                'updateAssets',
                null,
                InputOption::VALUE_NONE,
                'Whether to update assets or not.'
            )->addOption(
                'batchSize',
                null,
                InputOption::VALUE_OPTIONAL,
                'Enables batch import by setting the number of files to import each run. Automatically enables deleteOriginal option.',
                0
            )->addOption(
                'deleteOriginal',
                null,
                InputOption::VALUE_NONE,
                'Delete original file after successful import.'
            );
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->initializeOptions();
        if (!$this->initializeRootFolderAsset()) {
            return 1;
        }

        return $this->processFiles();
    }

    /**
     * @return int
     */
    protected function processFiles()
    {
        // Retrieve file list from path
        $path = $this->input->getArgument('path');
        $finder = new Finder();
        $finder->in($path)->files();

        $this->dump(sprintf(
            'Importing assets from "%s" to "%s".',
            $path,
            $this->rootFolderAsset->getFullPath()
        ));

        $batchCount = 1;
        foreach ($finder as $file) {
            // Handle batch limit
            if ($this->batchSize > 0 && $batchCount > $this->batchSize) {
                $this->dump('Batch size limit of ' . $this->batchSize . ' reached.');
                break;
            }

            if (!$this->createAssetFromFile($file)) {
                return 2;
            }

            // Ignore directories for batch count
            $batchCount++;
        }

        return 0;
    }

    /**
     * @param SplFileInfo $file
     * @return Asset|null
     */
    protected function getParentFolderAssetForFile(SplFileInfo $file)
    {
        $parentPath = $file->getRelativePath();

        // If file is in the root
        if (empty($parentPath)) {
            $parentAsset = $this->rootFolderAsset;

        } else {
            // Use existing asset
            $parentAssetPath = $this->rootFolderAsset->getFullPath() . '/' . $parentPath;
            if (Asset\Service::pathExists($parentAssetPath)) {
                $parentAsset = Asset\Folder::getByPath($parentAssetPath);

            // Create the folder structure if it doesn't exist
            } else {
                $parentAsset = $this->createFolderAssetsByPath($parentAssetPath);
            }
        }
        return $parentAsset;
    }

    /**
     * @param string $path
     * @return Asset\Folder
     */
    protected function createFolderAssetsByPath($path)
    {
        $pathParts = $this->trimExplode('/', $path, true);
        $currentLevelFolderAsset = $this->rootFolderAsset;
        $folderPath = '/';
        foreach ($pathParts as $pathPart) {
            $folderPath .= $pathPart . '/';

            // Use existing folder asset
            if (Asset\Service::pathExists($folderPath)) {
                $currentLevelFolderAsset = Asset\Folder::getByPath($folderPath);

            // Create folder asset and set is as current level for the next folder
            } else {
                $newFolder = new Asset\Folder();
                $newFolder->setFilename(basename($folderPath));
                $newFolder->setParent($currentLevelFolderAsset);
                $newFolder->save();
                $currentLevelFolderAsset = $newFolder;
            }
        }
        return $currentLevelFolderAsset;
    }

    /**
     * @param SplFileInfo $file
     * @return bool
     */
    protected function createAssetFromFile(SplFileInfo $file)
    {
        // If the asset already exists
        $expectedPath = $this->rootFolderAsset->getFullPath() . '/' . $file->getRelativePathname();
        if (Asset\Service::pathExists($expectedPath)) {

            // Update file contents if enabled
            if ($this->updateAssets) {
                $asset = Asset::getByPath($expectedPath);
                $asset->setData($file->getContents());
                $asset->save();
            }
            $this->deleteOriginalFile($file);
            $this->dump('Updating existing file "' . $expectedPath . '".');

        // If asset doesn't exist yet
        } else {
            // Check if parent asset exists
            $parentAsset = $this->getParentFolderAssetForFile($file);
            if ($parentAsset === null) {
                return false;
            }

            // Create new asset from file
            $this->dump('Importing file "' . $file->getRelativePathname() . '" to ' .
                $parentAsset->getFullPath() . '/' . $file->getBasename() . '.');
            Asset::create($parentAsset->getId(), [
                'filename'   => $file->getBasename(),
                'sourcePath' => $file->getPathname(),
                'data'       => $file->getContents(),
            ], true);
            $this->deleteOriginalFile($file);
        }

        $this->dump('Imported "' . $file->getRelativePathname() . '"');
        return true;
    }

    /**
     * @param SplFileInfo $file
     */
    protected function deleteOriginalFile(SplFileInfo $file)
    {
        // Delete original file
        if (!$file->isDir() && $this->deleteOriginal) {
            $absolutePath = $file->getPathname();
            $this->dump('Deleting original file: ' . $absolutePath);
            unlink($absolutePath);
        }
    }

    /**
     * @test
     */
    protected function initializeOptions()
    {
        $this->updateAssets = ($this->input->hasOption('updateAssets') && $this->input->getOption('updateAssets'));
        $this->deleteOriginal = ($this->input->hasOption('deleteOriginal') && $this->input->getOption('deleteOriginal'));

        if ($this->input->hasOption('batchSize')) {
            $batchSize = (int)$this->input->getOption('batchSize');
            if ($batchSize > 0) {
                $this->batchSize = $batchSize;
            }
        }
    }

    /**
     * @return bool
     */
    protected function initializeRootFolderAsset()
    {
        $rootPath = '/';
        if ($this->input->hasOption('rootPath')) {
            $optionValue = $this->input->getOption('rootPath');
            if (!empty($optionValue)) {
                // Add / after path
                if (substr($optionValue, -1) !== '/') {
                    $optionValue .= '/';
                }
                $rootPath = $optionValue;
            }
        }

        // Check if root folder exists
        if (!Asset\Service::pathExists($rootPath)) {
            $this->writeError('Unable to find/initialize root folder asset "' . $rootPath . '"');
            return false;
        }

        $this->rootFolderAsset = Asset\Folder::getByPath($rootPath);
        return true;
    }

    /**
     * @param string $delimiter
     * @param string $string
     * @param bool $removeEmptyValues
     * @return array
     */
    protected function trimExplode($delimiter, $string, $removeEmptyValues = false)
    {
        $result = explode($delimiter, $string);
        if ($removeEmptyValues) {
            $temp = [];
            foreach ($result as $value) {
                if (trim($value) !== '') {
                    $temp[] = $value;
                }
            }
            $result = $temp;
        }
        return array_map('trim', $result);
    }
}
