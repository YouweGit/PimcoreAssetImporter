<?php

namespace Youwe\PimcoreAssetImporterBundle\Command\Import;

use Pimcore\Console\AbstractCommand;
use Pimcore\File;
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
     * @var array
     */
    protected $includeExcludeOptions = [];

    /**
     * Configure asset import arguments and options
     */
    protected function configure()
    {
        $this
            ->setName('youwe:import:assets')
            ->setDescription('Import assets into Pimcore.')
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
                'Enables batch import by setting the number of files to import each run.',
                0
            )->addOption(
                'deleteOriginal',
                null,
                InputOption::VALUE_NONE,
                'Delete original file after successful import.'
            )->addOption(
                'includeTypes',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma separated list of allowed asset types. Will overrule excludeTypes. Available options: ' . implode(', ', Asset::getTypes())
            )->addOption(
                'excludeTypes',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma separated list of NOT allowed asset types. Available options: ' . implode(', ', Asset::getTypes())
            )->addOption(
                'includeExtensions',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma separated list of allowed file extensions. Will overrule excludeExtensions.'
            )->addOption(
                'excludeExtensions',
                null,
                InputOption::VALUE_OPTIONAL,
                'Comma separated list of NOT allowed file extensions.'
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
            'Importing assets from "%s" to "%s"',
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
            $parentAssetPath = $this->rootFolderAsset->getFullPath() . '/' . $this->fixInvalidDirectoryPath($parentPath);
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
        // If file is not allowed by include/exclude options
        if (!$this->isFileAllowed($file)) {
            $this->deleteOriginalFile($file);
            return true;
        }

        // If the asset already exists
        $expectedPath = $this->rootFolderAsset->getFullPath() .
            '/' . $file->getRelativePath() . '/' .
            File::getValidFilename($file->getBasename());
        if (Asset\Service::pathExists($expectedPath)) {

            // Update file contents if enabled
            if ($this->updateAssets) {
                $asset = Asset::getByPath($expectedPath);
                // If asset is allowed by include/exclude options
                if ($this->isAssetAllowed($asset)) {
                    // Update
                    $asset->setData($file->getContents());
                    $asset->save();
                    $this->dump('Updating existing file "' . $expectedPath . '".');
                }
            } else {
                $this->dump('NOT updating existing file "' . $expectedPath . '".');
            }

        // If asset doesn't exist yet
        } else {
            $newAsset = $this->createNewAssetFromFile($file);
            if ($newAsset !== null) {
                $this->dump('Imported ' . $newAsset->getType() . ' "' . $file->getRelativePathname() . '" to ' . $newAsset->getFullPath());
            }
        }
        $this->deleteOriginalFile($file);
        return true;
    }

    /**
     * @param SplFileInfo $file
     * @return null|Asset
     * @throws \Exception
     */
    protected function createNewAssetFromFile(SplFileInfo $file)
    {
        // Check if parent asset exists
        $parentAsset = $this->getParentFolderAssetForFile($file);
        if ($parentAsset === null) {
            throw new \Exception('Unable to find parent folder asset for file ' . $file->getRelativePathname());
        }

        // Create new asset from file
        $newAsset = Asset::create($parentAsset->getId(), [
            'filename'   => File::getValidFilename($file->getBasename()),
            'sourcePath' => $file->getPathname(),
            'data'       => $file->getContents(),
        ], false);

        // If asset is allowed by include/exclude options
        if ($this->isAssetAllowed($newAsset)) {
            // Save it
            $newAsset->save();
            return $newAsset;
        }
        // Otherwise delete it
        $newAsset->delete();
        return null;
    }

    /**
     * @param SplFileInfo $file
     * @return bool
     */
    protected function isFileAllowed(SplFileInfo $file)
    {
        $isAllowed = $this->checkIncludeExcludeOptions($file->getExtension(), 'includeExtensions', 'excludeExtensions');
        if (!$isAllowed) {
            $this->dump('File extension for ' . $file->getRelativePathname() . ' is not allowed.');
        }
        return $isAllowed;
    }

    /**
     * @param Asset $asset
     * @return bool
     */
    protected function isAssetAllowed(Asset $asset)
    {
        $isAllowed = $this->checkIncludeExcludeOptions($asset->getType(), 'includeTypes', 'excludeTypes');
        if (!$isAllowed) {

            $this->dump('Asset ' . $asset->getFullPath() . ' of type ' . $asset->getType() . ' is not allowed.');
        }
        return $isAllowed;
    }

    /**
     * @param string $value
     * @param string $includeOption
     * @param string $excludeOption
     * @return bool
     */
    protected function checkIncludeExcludeOptions($value, $includeOption, $excludeOption)
    {
        $isAllowed = true;
        foreach ([$includeOption, $excludeOption] as $option) {
            // If include option is not configured check exclude option
            if (empty($this->includeExcludeOptions[$option])) {
                continue;
            }
            // Check if the value matches one of the configured option values
            $isAllowed = in_array(strtolower($value), $this->includeExcludeOptions[$option]);

            // Negate exclude option
            if ($option === $excludeOption) {
                $isAllowed = !$isAllowed;
            }
            // Include option overrules exclude option
            break;
        }

        return $isAllowed;
    }

    /**
     * @param string $path
     * @return string
     */
    protected function fixInvalidDirectoryPath($path)
    {
        $basename = basename($path);
        // Remove numeric duplicate indicator from directory name. For example: dirname (1)
        $fixedBasename = preg_replace('/\([0-9]+\)/', '', $basename);
        $fixedBasename = File::getValidFilename($fixedBasename);
        return str_replace($basename, $fixedBasename, $path);
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

        $includeExcludeOptions = [
            'includeTypes',
            'excludeTypes',
            'includeExtensions',
            'excludeExtensions',
        ];
        foreach ($includeExcludeOptions as $option) {
            if (!$this->input->hasOption($option)) {
                $this->includeExcludeOptions[$option] = [];
                continue;
            }
            $optionValue = strtolower($this->input->getOption($option));
            $this->includeExcludeOptions[$option] = $this->trimExplode(',', $optionValue, true);
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
