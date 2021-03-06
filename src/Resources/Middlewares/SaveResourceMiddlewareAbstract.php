<?php
namespace Staticus\Resources\Middlewares;

use League\Flysystem\FilesystemInterface;
use Psr\Http\Message\UploadedFileInterface;
use Staticus\Config\ConfigInterface;
use Staticus\Exceptions\WrongRequestException;
use Staticus\Diactoros\Response\FileUploadedResponse;
use Staticus\Resources\Commands\BackupResourceCommand;
use Staticus\Resources\Commands\CopyResourceCommand;
use Staticus\Resources\Commands\DestroyEqualResourceCommand;
use Staticus\Resources\File\ResourceDO;
use Staticus\Middlewares\MiddlewareAbstract;
use Staticus\Diactoros\Response\FileContentResponse;
use Staticus\Resources\Exceptions\SaveResourceErrorException;
use Staticus\Exceptions\WrongResponseException;
use Staticus\Resources\ResourceDOInterface;
use Zend\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Zend\Diactoros\Stream;

abstract class SaveResourceMiddlewareAbstract extends MiddlewareAbstract
{
    protected $resourceDO;

    /**
     * @var FilesystemInterface
     */
    protected $filesystem;

    /**
     * @var ConfigInterface
     */
    protected $config;

    public function __construct(
        ResourceDOInterface $resourceDO,
        FilesystemInterface $filesystem,
        ConfigInterface $config
    )
    {
        $this->resourceDO = $resourceDO;
        $this->filesystem = $filesystem;
        $this->config = $config;
    }

    /**
     * @param ServerRequestInterface $request
     * @param ResponseInterface $response
     * @param callable|null $next
     * @return EmptyResponse
     * @throws \Exception
     */
    public function __invoke(
        ServerRequestInterface $request,
        ResponseInterface $response,
        callable $next = null
    )
    {
        parent::__invoke($request, $response, $next);
        if (
            $response instanceof FileContentResponse
            || $response instanceof FileUploadedResponse
        ) {
            $resourceDO = $this->resourceDO;
            $filePath = $resourceDO->getFilePath();
            if (empty($filePath)) {
                throw new WrongResponseException('Empty file path. File can\'t be saved.');
            }
            $resourceStream = $response->getResource();
            if (is_resource($resourceStream)) {
                $this->save($resourceDO, $resourceStream);
            } else {
                $body = $response->getContent();

                // If any previous middlewares have not created the resource content, just skip it.
                // It is ok in some special cases like image resizing, when generator should not do anything,
                // but next middlewares will create resource from original model.
                if (null !== $body) {

                    $this->save($resourceDO, $body);
                }
            }
            $this->response = new EmptyResponse($response->getStatusCode(), [
                'Content-Type' => $this->resourceDO->getMimeType(),
            ]);
        }

        return $this->next();
    }

    /**
     * @param $filePath
     * @param $content
     */
    protected function writeFile($filePath, $content)
    {
        if (is_resource($content)) {
            $result = $this->filesystem->putStream($filePath, $content);
        } else {
            $result = $this->filesystem->put($filePath, $content);
        }
        if (!$result) {
            throw new SaveResourceErrorException('File cannot be written to the path ' . $filePath);
        }
    }

    protected function uploadFile(UploadedFileInterface $content, $mime, $filePath)
    {
        $uri = $content->getStream()->getMetadata('uri');
        if (!$uri) {
            throw new SaveResourceErrorException('Unknown error: can\'t get uploaded file uri');
        }
        $uploadedMime = $this->filesystem->getMimetype($uri);
        if ($mime !== $uploadedMime) {
            /**
             * Try to remove unnecessary file because UploadFile object can be emulated
             * @see \Staticus\Middlewares\ActionPostAbstract::download
             */
            $this->filesystem->delete($uri);
            throw new WrongRequestException('Bad request: incorrect mime-type of the uploaded file');
        }
        $content->moveTo($filePath);
    }

    protected function copyResource(ResourceDOInterface $originResourceDO, ResourceDOInterface $newResourceDO)
    {
        $command = new CopyResourceCommand($originResourceDO, $newResourceDO, $this->filesystem);

        return $command();
    }

    /**
     * @param $directory
     * @throws SaveResourceErrorException
     * @see \Staticus\Resources\Middlewares\Image\ImagePostProcessingMiddlewareAbstract::createDirectory
     */
    protected function createDirectory($directory)
    {
        if (!$this->filesystem->createDir($directory)) {
            throw new SaveResourceErrorException('Can\'t create a directory: ' . $directory);
        }
    }

    protected function copyFileToDefaults(ResourceDOInterface $resourceDO)
    {
        if (
            ResourceDO::DEFAULT_VARIANT !== $resourceDO->getVariant()
            && $this->config->get('staticus.magic_defaults.variant')
        ) {
            $defaultDO = clone $resourceDO;
            $defaultDO->setVariant();
            $defaultDO->setVersion();
            $this->copyResource($resourceDO, $defaultDO);
        }
        if (
            ResourceDO::DEFAULT_VERSION !== $resourceDO->getVersion()
            && $this->config->get('staticus.magic_defaults.version')
        ) {
            $defaultDO = clone $resourceDO;
            $defaultDO->setVersion();
            $this->copyResource($resourceDO, $defaultDO);
        }
    }

    /**
     * @param ResourceDOInterface $resourceDO
     * @param string|resource|Stream $content
     * @return ResourceDOInterface
     * @throws \RuntimeException if the upload was not successful.
     * @throws \InvalidArgumentException if the $path specified is invalid.
     * @throws \RuntimeException on any error during the move operation, or on
     */
    protected function save(ResourceDOInterface $resourceDO, $content)
    {
        $backupResourceVerDO = null;
        $filePath = $resourceDO->getFilePath();
        $this->createDirectory(dirname($filePath));
        // backups don't needs if this is a 'new creation' command
        if ($resourceDO->isRecreate()) {
            $backupResourceVerDO = $this->backup($resourceDO);
        }
        if ($content instanceof UploadedFileInterface) {
            $this->uploadFile($content, $resourceDO->getMimeType(), $filePath);
        } else {
            $this->writeFile($filePath, $content);
        }

        $responseDO = $resourceDO;
        if ($backupResourceVerDO instanceof ResourceDOInterface
            && $backupResourceVerDO->getVersion() !== ResourceDOInterface::DEFAULT_VERSION) {
            // If the newly created file is the same as the previous version, remove backup immediately
            $responseDO = $this->destroyEqual($resourceDO, $backupResourceVerDO);
        }
        if ($responseDO === $resourceDO) {

            // cleanup postprocessing cache folders
            // - if it is a new file creation (remove possible garbage after other operations)
            // - or if the basic file is replaced and not equal to the previous version
            $this->afterSave($resourceDO);
        }
        if ($this->config->get('staticus.magic_defaults.allow')) {
            $this->copyFileToDefaults($resourceDO);
        }
        return $resourceDO;
    }
    abstract protected function afterSave(ResourceDOInterface $resourceDO);

    protected function backup(ResourceDOInterface $resourceDO)
    {
        $command = new BackupResourceCommand($resourceDO, $this->filesystem);
        $backupResourceVerDO = $command();

        return $backupResourceVerDO;
    }

    /**
     * @param ResourceDOInterface $resourceDO
     * @param ResourceDOInterface $backupResourceVerDO
     * @return mixed
     */
    protected function destroyEqual(ResourceDOInterface $resourceDO, ResourceDOInterface $backupResourceVerDO)
    {
        $command = new DestroyEqualResourceCommand($resourceDO, $backupResourceVerDO, $this->filesystem);
        $responseDO = $command();

        return $responseDO;
    }
}
