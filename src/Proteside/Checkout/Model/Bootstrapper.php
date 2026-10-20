<?php
/**
 * Reads the bundled bootstrapper (etc/proteside/bootstrapper.min.js + VERSION) once per process.
 *
 * The bootstrapper ships inside the module: it is never downloaded from the
 * CDN at runtime and it updates together with the module version. The file is emitted byte for
 * byte so that, without inline config, its sha256 is exactly the one in etc/csp_whitelist.xml.
 *
 * @package   Proteside_Checkout
 * @license   MIT
 */

declare(strict_types=1);

namespace Proteside\Checkout\Model;

use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use Psr\Log\LoggerInterface;

class Bootstrapper
{
    public const MODULE_NAME = 'Proteside_Checkout';
    public const ASSET_DIR = 'proteside';
    public const FILE_MIN = 'bootstrapper.min.js';
    public const FILE_VERSION = 'VERSION';

    private ModuleDirReader $moduleDirReader;
    private FileDriver $fileDriver;
    private LoggerInterface $logger;
    private Snippet $snippet;

    private ?string $js = null;
    private ?string $version = null;

    public function __construct(
        ModuleDirReader $moduleDirReader,
        FileDriver $fileDriver,
        LoggerInterface $logger,
        Snippet $snippet
    ) {
        $this->moduleDirReader = $moduleDirReader;
        $this->fileDriver = $fileDriver;
        $this->logger = $logger;
        $this->snippet = $snippet;
    }

    /**
     * Raw content of bootstrapper.min.js ('' if unreadable — the caller must then not inject).
     */
    public function getJs(): string
    {
        if ($this->js === null) {
            $this->js = $this->read(self::FILE_MIN);
            if ($this->js !== '' && strpos($this->js, Snippet::USE_STRICT) !== 0) {
                $this->logger->warning('Proteside: bundled bootstrapper does not start with "use strict"; inline config will be prepended instead.');
            }
        }
        return $this->js;
    }

    public function getVersion(): string
    {
        if ($this->version === null) {
            $this->version = trim($this->read(self::FILE_VERSION));
        }
        return $this->version;
    }

    /**
     * sha256 (base64) of the plain bootstrapper, for diagnostics/CSP documentation.
     */
    public function getHash(): string
    {
        $js = $this->getJs();
        return $js === '' ? '' : $this->snippet->cspHash($js);
    }

    public function isAvailable(): bool
    {
        return $this->getJs() !== '';
    }

    private function read(string $file): string
    {
        try {
            $dir = $this->moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, self::MODULE_NAME);
            $path = rtrim($dir, '/') . '/' . self::ASSET_DIR . '/' . $file;
            if (!$this->fileDriver->isReadable($path)) {
                $this->logger->error(sprintf('Proteside: bundled asset %s is not readable.', $path));
                return '';
            }
            $content = $this->fileDriver->fileGetContents($path);
            return is_string($content) ? $content : '';
        } catch (\Throwable $e) {
            $this->logger->error('Proteside: cannot read bundled asset ' . $file . ': ' . $e->getMessage());
            return '';
        }
    }
}
