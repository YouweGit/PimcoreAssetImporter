<?php

namespace Youwe\PimcoreAssetImporterBundle;

use Pimcore\Extension\Bundle\AbstractPimcoreBundle;
use Pimcore\Extension\Bundle\Traits\PackageVersionTrait;

class YouwePimcoreAssetImporterBundle extends AbstractPimcoreBundle
{
    use PackageVersionTrait;

    protected function getComposerPackageName(): string
    {
        return 'youwe/pimcore-asset-importer';
    }
}
