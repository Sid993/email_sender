<?php
/**
 * Saurav Kumar.
 *
 * @category  Saurav Kumar
 * @package   Avinash_EmailSender
 * @author    Saurav Kumar
 */
namespace Avinash\EmailSender\Model;

class Store
{
    /**
     * @var int/null
     */
    private $storeId = null;

    /**
     * @var string
     */
    private $from = null;

    /**
     * Get StoreId
     *
     * @return int|null
     */
    public function getStoreId()
    {
        return $this->storeId;
    }

    /**
     * Set StoreId
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId($storeId)
    {
        $this->storeId = $storeId;
        return $this;
    }

    /**
     * Get From
     *
     * @return string|array
     */
    public function getFrom()
    {
        return $this->from;
    }

    /**
     * Set From
     *
     * @param string|array $from
     * @return $this
     */
    public function setFrom($from)
    {
        $this->from = $from;
        return $this;
    }
}
