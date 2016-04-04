<?php
namespace Voice\Action;

use Zend\Diactoros\Response\EmptyResponse;

class VoiceActionPost extends VoiceActionAbstract
{
    protected function action()
    {
        $params = $this->request->getQueryParams('recreate');
        if (!file_exists($this->voiceFilePath) || !empty($params['recreate'])) {
            $this->generate($this->text, $this->voiceFilePath);

            // HTTP 201 Created
            return new EmptyResponse(201, static::$defaultHeaders);
        }

        // HTTP 304 Not Modified
        return new EmptyResponse(304, static::$defaultHeaders);
    }
}
