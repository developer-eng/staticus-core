<?php
namespace Staticus\Resources\Gif;

use Staticus\Resources\PrepareResourceMiddlewareAbstract;
use Staticus\Config\Config;

class PrepareResourceMiddleware extends PrepareResourceMiddlewareAbstract
{
    public function __construct(ResourceDO $resourceDO, Config $config)
    {
        parent::__construct($resourceDO, $config);
    }
}
