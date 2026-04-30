<?php

namespace CreativeArc\ArcHaystack\Extensions;

class RequestState
{
    public static bool $tagDidLog = false;
    public static bool $didWrite = false;

    /**
     * @var string[]
     */
    public static array $fetchedPaths = [];

    /**
     * @var string[]
     */
    public static array $templateContent = [];
}
