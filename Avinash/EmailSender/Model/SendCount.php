<?php
/**
 * Saurav Kumar
 *
 * @category   Saurav
 * @package    Avinash_EmailSender
 * @author     Saurav Kumar
 */
namespace Avinash\EmailSender\Model;

/**
 * SendCount Model Class
 */
class SendCount extends \Magento\Framework\Model\AbstractModel implements \Magento\Framework\DataObject\IdentityInterface, \Avinash\EmailSender\Api\Data\SendCountInterface
{
    final public const NOROUTE_ENTITY_ID = 'no-route';

    final public const CACHE_TAG = 'avinash_emailsender_sendcount';

    protected $_cacheTag = 'avinash_emailsender_sendcount';

    protected $_eventPrefix = 'avinash_emailsender_sendcount';

    /**
     * Set resource model
     */
    public function _construct()
    {
        $this->_init(\Avinash\EmailSender\Model\ResourceModel\SendCount::class);
    }

    /**
     * Load No-Route Indexer.
     *
     * @return $this
     */
    public function noRouteReasons()
    {
        return $this->load(self::NOROUTE_ENTITY_ID, $this->getIdFieldName());
    }

    /**
     * Get identities.
     *
     * @return []
     */
    public function getIdentities()
    {
        return [self::CACHE_TAG.'_'.$this->getId()];
    }

    /**
     * Set EntityId
     *
     * @param int $entityId
     * @return \Avinash\EmailSender\Model\SendCountInterface
     */
    public function setEntityId($entityId)
    {
        return $this->setData(self::ENTITY_ID, $entityId);
    }

    /**
     * Get EntityId
     *
     * @return int
     */
    public function getEntityId()
    {
        return parent::getData(self::ENTITY_ID);
    }

    /**
     * Set SendCount
     *
     * @param int $sendCount
     * @return \Avinash\EmailSender\Model\SendCountInterface
     */
    public function setSendCount($sendCount)
    {
        return $this->setData(self::SEND_COUNT, $sendCount);
    }

    /**
     * Get SendCount
     *
     * @return int
     */
    public function getSendCount()
    {
        return parent::getData(self::SEND_COUNT);
    }

    /**
     * Set FileName
     *
     * @param string $fileName
     * @return \Avinash\EmailSender\Model\SendCountInterface
     */
    public function setFileName($fileName)
    {
        return $this->setData(self::FILE_NAME, $fileName);
    }

    /**
     * Get FileName
     *
     * @return string
     */
    public function getFileName()
    {
        return parent::getData(self::FILE_NAME);
    }

    /**
     * Set CreatedAt
     *
     * @param string $createdAt
     * @return \Avinash\EmailSender\Model\SendCountInterface
     */
    public function setCreatedAt($createdAt)
    {
        return $this->setData(self::CREATED_AT, $createdAt);
    }

    /**
     * Get CreatedAt
     *
     * @return string
     */
    public function getCreatedAt()
    {
        return parent::getData(self::CREATED_AT);
    }
}

