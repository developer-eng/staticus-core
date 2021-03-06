<?php
namespace Staticus\Resources\Middlewares\Image;

use Staticus\Exceptions\WrongRequestException;
use Staticus\Resources\Image\CropImageDO;
use Staticus\Resources\Image\ResourceImageDO;
use Staticus\Resources\Image\ResourceImageDOInterface;
use Staticus\Resources\Middlewares\PrepareResourceMiddlewareAbstract;

abstract class PrepareImageMiddlewareAbstract extends PrepareResourceMiddlewareAbstract
{
    protected function fillResourceSpecialFields()
    {
        $size = static::getParamFromRequest('size', $this->request);
        $this->parseSizeParameter($size);
        $crop = static::getParamFromRequest('crop', $this->request);
        $this->parseCropParameter($crop);
    }

    protected function parseCropParameter($crop)
    {
        if ($crop) {
            /* @var ResourceImageDOInterface $resource */
            $resource = $this->resourceDO;
            $crop = explode('x', $crop);
            if (count($crop) != 4) {
                throw new WrongRequestException(
                    'Crop parameter has to consist of four parts, concatenated by "x" char.'
                );
            }

            $cropObject = new CropImageDO();
            $cropObject->setX((int) $crop[0]);
            $cropObject->setY((int) $crop[1]);
            $cropObject->setWidth((int) $crop[2]);
            $cropObject->setHeight((int) $crop[3]);

            if (!$resource->getWidth() || !$cropObject->getHeight()) {
                throw new WrongRequestException(
                    'You should send the size=[X]x[Y] parameter together with the crop parameter'
                );
            }
            if ($cropObject->getX() < 0 || $cropObject->getY() < 0 ||
                $cropObject->getWidth() < 1 || $cropObject->getHeight() < 1
            ) {
                throw new WrongRequestException(
                    'Crop parameters can not be less than zero'
                );
            }

            $resource->setCrop($cropObject);
        }
    }

    protected function parseSizeParameter($size)
    {
        $width = ResourceImageDO::DEFAULT_WIDTH;
        $height = ResourceImageDO::DEFAULT_HEIGHT;
        $resource = $this->resourceDO;
        if ($size) {
            $size = explode('x', $size);
            if (!empty($size[0]) && !empty($size[1])) {
                $width = (int)$size[0];
                $height = (int)$size[1];
                if ($width < 0 || $height < 0) {
                    throw new WrongRequestException('Sizes can not be less than zero');
                }
                if ($width && $height) {
                    $allowedSizes = $this->config->get('staticus.images.sizes');
                    if (!in_array([$width, $height], $allowedSizes)) {
                        throw new WrongRequestException('Resource size is not allowed: ' . $width . 'x' . $height);
                    }
                }
            }
        }
        /** @var ResourceImageDOInterface $resource */
        $resource->setWidth($width);
        $resource->setHeight($height);
    }
}
