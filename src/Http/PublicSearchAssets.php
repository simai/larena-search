<?php

declare(strict_types=1);

namespace Larena\Search\Http;

use Larena\Ui\Frontend\FrontendRuntimeAssetResolver;
use Larena\Ui\Frontend\FrontendRuntimeLock;

final class PublicSearchAssets
{
    /** @return list<array{kind:string,path:string,key:string}> */
    public function all(): array
    {
        $lock = FrontendRuntimeLock::bundled();
        $base = '/larena/assets/sf/' . rawurlencode($lock->bundleId()) . '/';
        $assets = [['kind' => 'javascript', 'path' => '/larena/assets/admin/simai.framework.boot.js?v=' . rawurlencode($lock->bundleId()), 'key' => 'simai.framework.boot.js']];
        foreach (FrontendRuntimeAssetResolver::bundled()->resolve(FrontendRuntimeAssetResolver::coreGraph()) as $asset) {
            $assets[] = ['kind' => $asset['kind'], 'path' => $base . $asset['relative_path'], 'key' => $asset['asset_key']];
        }
        $assets[] = ['kind' => 'javascript', 'path' => '/larena/assets/admin/simai.framework.bridge.js?v=' . rawurlencode($lock->bundleId()), 'key' => 'simai.framework.bridge.js'];

        return $assets;
    }
}
