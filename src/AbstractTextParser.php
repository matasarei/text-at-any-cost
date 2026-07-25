<?php

namespace TextAtAnyCost;

/**
 * Class AbstractTextParser
 *
 * @package TextAtAnyCost
 */
abstract class AbstractTextParser
{
    /**
     * @var string|mixed
     */
    protected $data;

    /**
     * @param $filename
     */
    public function __construct($filename)
    {
        $this->data = file_get_contents($filename);
    }

    /**
     * @return string|null
     */
    abstract function parse();
}
