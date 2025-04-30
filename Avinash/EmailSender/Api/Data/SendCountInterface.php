<?php
/**
 * Saurav Kumar
 *
 * @category   Saurav
 * @package    Avinash_EmailSender
 * @author     Saurav Kumar
 */
namespace Avinash\EmailSender\Api\Data;

/**
 * SendCount Model Interface
 */
interface SendCountInterface
{
    public const ENTITY_ID = 'entity_id';

    public const SEND_COUNT = 'send_count';

    public const FILE_NAME = 'file_name';

    public const CREATED_AT = 'created_at';

    /**
     * Set EntityId
     *
     * @param int $entityId
     * @return \Avinash\EmailSender\Api\Data\SendCountInterface
     */
    public function setEntityId($entityId);
    /**
     * Get EntityId
     *
     * @return int
     */
    public function getEntityId();
    /**
     * Set SendCount
     *
     * @param int $sendCount
     * @return \Avinash\EmailSender\Api\Data\SendCountInterface
     */
    public function setSendCount($sendCount);
    /**
     * Get SendCount
     *
     * @return int
     */
    public function getSendCount();
    /**
     * Set FileName
     *
     * @param string $fileName
     * @return \Avinash\EmailSender\Api\Data\SendCountInterface
     */
    public function setFileName($fileName);
    /**
     * Get FileName
     *
     * @return string
     */
    public function getFileName();
    /**
     * Set CreatedAt
     *
     * @param string $createdAt
     * @return \Avinash\EmailSender\Api\Data\SendCountInterface
     */
    public function setCreatedAt($createdAt);
    /**
     * Get CreatedAt
     *
     * @return string
     */
    public function getCreatedAt();
}

