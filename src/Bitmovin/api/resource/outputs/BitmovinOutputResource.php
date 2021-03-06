<?php

namespace Bitmovin\api\resource\outputs;

use Bitmovin\api\model\outputs\Output;
use Bitmovin\api\resource\AbstractResource;

abstract class BitmovinOutputResource extends AbstractResource
{
    const LIST_NAME = 'items';

    /**
     * OutputResource constructor.
     *
     * @param string $baseUri
     * @param string $modelClassName
     * @param string $apiKey
     */
    public function __construct($baseUri, $modelClassName, $apiKey)
    {
        parent::__construct($baseUri, $modelClassName, static::LIST_NAME, $apiKey);
    }

    /**
     * @param Output $output
     *
     * @return Output
     * @throws \Bitmovin\api\exceptions\BitmovinException
     */
    protected function getOutput(Output $output)
    {
        return $this->getOutputById($output->getId());
    }

    /**
     * @param $outputId
     *
     * @return Output
     * @throws \Bitmovin\api\exceptions\BitmovinException
     */
    protected function getOutputById($outputId)
    {
        /** @var Output $output */
        $output = $this->getResource($outputId);

        return $output;
    }
}