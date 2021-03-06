<?php
namespace Staticus\Resources\Middlewares\Image;

use League\Flysystem\FilesystemInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Staticus\Config\ConfigInterface;
use Staticus\Resources\ResourceDOInterface;
use Zend\Expressive\Container\Exception\NotFoundException;

abstract class ImageResizeMiddlewareAbstract extends ImagePostProcessingMiddlewareAbstract
{
    /**
     * @var ConfigInterface
     */
    public $config;

    public function __construct(
        ResourceDOInterface $resourceDO
        , FilesystemInterface $filesystem
        , ConfigInterface $config
    )
    {
        parent::__construct($resourceDO, $filesystem);
        $this->config = $config;
    }

    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $next = null
    )
    {
        parent::__invoke($request, $response, $next);
        if (!$this->isSupportedResponse($response)) {

            return $next($request, $response);
        }
        if ($this->resourceDO->getDimension()) {
            $path = $this->resourceDO->getFilePath();

            // (POST) Resource just created or re-created
            if ($this->resourceDO->isNew()
                || $this->resourceDO->isRecreate()
            ) {
                /**
                 * Explanation: If it's just an artifact that is left from the previous file after re-creation
                 * than you need to remove it exact in recreation moment
                 * @see \Staticus\Resources\Middlewares\Image\SaveImageMiddlewareAbstract::afterSave
                 */
                // Some of previous middlewares already created this file size
                if ($this->filesystem->has($path)) {
                    $this->resizeImage($path, $path, $this->resourceDO->getWidth(), $this->resourceDO->getHeight());
                } else {
                    $modelResourceDO = $this->getResourceWithoutSizes();
                    $this->resizeImage(
                        $modelResourceDO->getFilePath()
                        , $path
                        , $this->resourceDO->getWidth()
                        , $this->resourceDO->getHeight()
                    );
                }

            // (GET) Resource should be exist, just check if this size wasn't created before
            } else if (!$this->filesystem->has($path)) {
                $modelResourceDO = $this->getResourceWithoutSizes();
                $this->resizeImage(
                    $modelResourceDO->getFilePath()
                    , $path
                    , $this->resourceDO->getWidth()
                    , $this->resourceDO->getHeight()
                );
            }
        }

        return $next($request, $response);
    }

    public function resizeImage($sourcePath, $destinationPath, $width, $height)
    {
        if (!$this->filesystem->has($sourcePath)) {
            throw new NotFoundException('Can not resize. Resource is not found');
        }
        $this->createDirectory(dirname($destinationPath));
        $imagick = $this->getImagick($sourcePath);
        if ($this->config->get('staticus.images.resize.autocrop', false)) {
            $imagick->cropThumbnailImage($width, $height);
        } else {
            $imagick->adaptiveResizeImage($width, $height, true);
        }
        $imagick->writeImage($destinationPath);
        $imagick->clear();
        $imagick->destroy();
    }
}
