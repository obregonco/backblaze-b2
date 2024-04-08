<?php

namespace obregonco\B2\Access;

/**
 * @author Luca Saladino <sal65535@protonmail.com>
 */
class Capabilities
{
    public const LIST_KEYS = "listKeys";
    public const WRITE_KEYS = "writeKeys";
    public const DELETE_KEYS = "deleteKeys";
    public const LIST_ALL_BUCKET_NAMES = "listAllBucketNames";
    public const LIST_BUCKETS = "listBuckets";
    public const READ_BUCKETS = "readBuckets";
    public const WRITE_BUCKETS = "writeBuckets";
    public const DELETE_BUCKETS = "deleteBuckets";
    public const READ_BUCKET_RETENTIONS = "readBucketRetentions";
    public const WRITE_BUCKET_RETENTIONS = "writeBucketRetentions";
    public const READ_BUCKET_ENCRYPTION = "readBucketEncryption";
    public const WRITE_BUCKET_ENCRYPTION = "writeBucketEncryption";
    public const LIST_FILES = "listFiles";
    public const READ_FILES = "readFiles";
    public const SHARE_FILES = "shareFiles";
    public const WRITE_FILES = "writeFiles";
    public const DELETE_FILES = "deleteFiles";
    public const READ_FILE_LEGAL_HOLDS = "readFileLegalHolds";
    public const WRITE_FILE_LEGAL_HOLDS = "writeFileLegalHolds";
    public const READ_FILE_RETENTIONS = "readFileRetentions";
    public const WRITE_FILE_RETENTIONS = "writeFileRetentions";
    public const BYPASS_GOVERNANCE = "bypassGovernance";

    /** @var array */
    protected $capabilities;

	/**
	 * @param array $capabilities
	 */
	public function __construct(array $capabilities)
	{
		$this->capabilities = $capabilities;
	}

    /**
     * @return array
     */
    public function getCapabilities()
    {
        return $this->capabilities;
    }

    /**
     * @param array $capabilities
     * @return self
     */
    public function setCapabilities(array $capabilities)
    {
        $this->capabilities = $capabilities;

        return $this;
    }

    /**
     * @param string $capability
     * @return void
     */
    public function addCapability(string $capability)
    {
        $this->capabilities[] = $capability;
    }
}