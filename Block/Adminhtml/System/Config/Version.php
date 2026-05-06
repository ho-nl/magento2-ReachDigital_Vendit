<?php

declare(strict_types=1);

namespace ReachDigital\Vendit\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\HTTP\Client\Curl;

class Version extends Field
{
    private const PACKAGE_NAME = 'reach-digital/magento2-vendit';
    private const PACKAGIST_API_URL = 'https://repo.packagist.org/p2/reach-digital/magento2-vendit.json';
    private const CACHE_KEY = 'vendit_packagist_latest_version';
    private const CACHE_TTL = 3600;

    public function __construct(
        Context $context,
        private readonly DirectoryList $directoryList,
        private readonly CacheInterface $cache,
        private readonly Curl $curl,
        array $data = [],
    ) {
        parent::__construct($context, $data);
    }

    protected function _getElementHtml(AbstractElement $element): string
    {
        $installed = $this->getInstalledVersion();
        $latest = $this->getLatestVersion();

        if (!$installed) {
            return '<em>Version not found</em>';
        }

        $badge = $this->renderBadge($installed, $latest);

        return sprintf(
            '<span style="font-size: 14px; font-weight: 600;">%s</span>%s',
            $this->escapeHtml($installed),
            $badge,
        );
    }

    private function renderBadge(string $installed, ?string $latest): string
    {
        $baseStyle = 'display:inline-block; margin-left:8px; padding:2px 8px; border-radius:3px; font-size:11px; font-weight:600; vertical-align:middle; letter-spacing:0.03em;';

        if ($latest === null) {
            return '';
        }

        if (version_compare(ltrim($installed, 'v'), ltrim($latest, 'v'), '<')) {
            return sprintf(
                ' <a href="https://packagist.org/packages/%s#%s" target="_blank" style="%s background:#eb5202; color:#fff; text-decoration:none;">&#8593; %s available</a>',
                self::PACKAGE_NAME,
                $this->escapeHtml($latest),
                $baseStyle,
                $this->escapeHtml($latest),
            );
        }

        return sprintf(
            '<span style="%s background:#e5f5e5; color:#2d6a2d; border:1px solid #b2d8b2;">&#10003; up to date</span>',
            $baseStyle,
        );
    }

    private function getInstalledVersion(): ?string
    {
        try {
            $lockFile = $this->directoryList->getRoot() . '/composer.lock';
            if (!file_exists($lockFile)) {
                return null;
            }

            $lock = json_decode(file_get_contents($lockFile), true);
            foreach ($lock['packages'] ?? [] as $package) {
                if ($package['name'] !== self::PACKAGE_NAME) {
                    continue;
                }

                $version = $package['version'] ?? 'dev-main';
                $label = str_starts_with($version, 'dev-') ? $version : 'v' . ltrim($version, 'v');

                return $label;
            }
        } catch (\Exception) {
            // Fall through to null
        }

        return null;
    }

    private function getLatestVersion(): ?string
    {
        try {
            $cached = $this->cache->load(self::CACHE_KEY);
            if ($cached !== false) {
                return $cached ?: null;
            }

            $this->curl->setTimeout(5);
            $this->curl->get(self::PACKAGIST_API_URL);

            if ($this->curl->getStatus() !== 200) {
                $this->cache->save('', self::CACHE_KEY, [], self::CACHE_TTL);
                return null;
            }

            $data = json_decode($this->curl->getBody(), true);
            $entries = $data['packages'][self::PACKAGE_NAME] ?? [];
            $versions = array_column($entries, 'version');

            // Keep only stable semver tags (e.g. v1.0.4 or 1.0.4), exclude dev-* and aliases
            $stable = array_filter($versions, fn($v) => preg_match('/^\d+\.\d+\.\d+$|^v\d+\.\d+\.\d+$/', $v));

            if (empty($stable)) {
                $this->cache->save('', self::CACHE_KEY, [], self::CACHE_TTL);
                return null;
            }

            usort($stable, fn($a, $b) => version_compare(ltrim($a, 'v'), ltrim($b, 'v')));
            $latest = 'v' . ltrim(end($stable), 'v');

            $this->cache->save($latest, self::CACHE_KEY, [], self::CACHE_TTL);

            return $latest;
        } catch (\Exception) {
            return null;
        }
    }
}
