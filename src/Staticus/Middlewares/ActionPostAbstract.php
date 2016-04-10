<?php
namespace Staticus\Middlewares;

use Staticus\Diactoros\FileContentResponse\FileUploadedResponse;
use Staticus\Resources\Middlewares\PrepareResourceMiddlewareAbstract;
use Staticus\Resources\ResourceDOInterface;
use Staticus\Diactoros\FileContentResponse\FileContentResponse;
use Zend\Diactoros\Response\EmptyResponse;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Staticus\Resources\File\ResourceDO;
use Zend\Diactoros\UploadedFile;

abstract class ActionPostAbstract extends MiddlewareAbstract
{
    /**
     * @var mixed
     */
    protected $generator;
    /**
     * @var ResourceDO
     */
    protected $resourceDO;

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
        $this->response = $this->action();

        return $this->next();
    }

    abstract protected function generate(ResourceDOInterface $resourceDO, $filePath);

    protected function action()
    {
        $headers = [
            'Content-Type' => $this->resourceDO->getMimeType(),
        ];
        $filePath = $this->resourceDO->getFilePath();
        $fileExists = is_file($filePath);
        $recreate = PrepareResourceMiddlewareAbstract::getParamFromRequest('recreate', $this->request);
        $recreate = $fileExists && $recreate;
        if (!$fileExists || $recreate) {
            $this->resourceDO->setRecreate($recreate);
            $body = $this->upload();
            if ($body) {

                /** @see \Zend\Diactoros\Response::$phrases */
                return new FileUploadedResponse($body, 201, $headers);
            } else {
                $body = $this->generate($this->resourceDO, $filePath);

                /** @see \Zend\Diactoros\Response::$phrases */
                return new FileContentResponse($body, 201, $headers);
            }

        }

        /** @see \Zend\Diactoros\Response::$phrases */
        return new EmptyResponse(304, $headers);
    }

    /**
     * @return string|null
     */
    protected function upload()
    {
        $uploaded = $this->request->getUploadedFiles();
        $uploaded = current($uploaded);
        if ($uploaded instanceof UploadedFile) {

            return $uploaded;
        }

        return null;
    }
}