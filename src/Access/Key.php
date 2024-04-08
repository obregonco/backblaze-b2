<?php

namespace obregonco\B2\Access;

/**
 * @author Luca Saladino <sal65535@protonmail.com>
 */
class Key
{
    /** @var string */
    protected $accountId;

    /** @var string */
    protected $keyId;

    /** @var string */
    protected $applicationKey;

    /** @var ?Capabilities */
    protected $capabilities;

    /**
     * @param string $keyId
     * @param string $applicationKey
     */
    public function __construct($accountId, $keyId, $applicationKey, ?Capabilities $capabilities = null)
    {
        $this->accountId = $accountId;
        $this->keyId = $keyId;
        $this->applicationKey = $applicationKey;
        $this->capabilities = $capabilities;
    }
    /**
     * @return string
     */
    public function getKeyId()
    {
        return $this->keyId;
    }

    /**
     * @return string
     */
    public function getApplicationKey()
    {
        return $this->applicationKey;
    }

    /**
     * @param string $keyId
     * @return self
     */
    public function setKeyId($keyId)
    {
        $this->keyId = $keyId;
        return $this;
    }

    /**
     * @param string $applicationKey
     * @return self
     */
    public function setApplicationKey($applicationKey)
    {
        $this->applicationKey = $applicationKey;
        return $this;
    }


    public function getAccountId(): string
    {
        return $this->accountId;
    }

    /**
     * @param string $accountId
     * @return self
     */
    public function setAccountId($accountId): self
    {
        $this->accountId = $accountId;
        return $this;
    }

    /**
     * @return Capabilities
     */
    public function getCapabilities()
    {
        return $this->capabilities;
    }

    /**
     * @param Capabilities $capabilities
     * @return self
     */
    public function setCapabilities($capabilities)
    {
        $this->capabilities = $capabilities;
        return $this;
    }
}
