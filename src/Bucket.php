<?php

namespace obregonco\B2;

class Bucket
{
    public const TYPE_PUBLIC = 'allPublic';
    public const TYPE_PRIVATE = 'allPrivate';

    protected $bucketId;
    protected $bucketName;
    protected $bucketType;
    protected $bucketInfo;
    protected $corsRules;
    protected $fileLockConfiguration;
    protected $defaultServerSideEncryption;
    protected $lifecycleRules;
    protected $revision;
    protected $options;

    /**
     * Bucket constructor.
     */
    public function __construct(array $values)
    {
        foreach ($values as $key => $value) {
            $this->{$key} = $value;
        }
    }

    public function getId()
    {
        return $this->bucketId;
    }

    public function getName()
    {
        return $this->bucketName;
    }

    public function getType()
    {
        return $this->bucketType;
    }

    public function getRevision()
    {
        return $this->revision;
    }

    public function getCORSRules()
    {
        return $this->corsRules;
    }

    public function getLifeCycleRules()
    {
        return $this->lifecycleRules;
    }
}
