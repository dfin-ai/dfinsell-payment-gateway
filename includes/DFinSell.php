<?php

class DFinSell
{
    /**
     * @var string $publicKey public API key used to authenticate requests.
     */
    public static $publicKey;

    /**
     * @var string $privateKey private API key used to authenticate requests.
     */
    public static $privateKey;


    /**
     * @var string $apiBaseLiveUrl URL of the live API endpoint
     */
    // public static $apiBaseLiveUrl = DFinSell_Constants::API_BASE_LIVE_URL;

    /**
     * @var string $apiBaseSandboxUrl URL of the sandbox API endpoint
     */
    // public static $apiBaseSandboxUrl = DFinSell_Constants::API_BASE_SANDBOX_URL;

    /**
     * @var string $userAgent User-agent string send with requests.
     */
    public static $userAgent = null;

}
