<?php
/**
 * Saurav Kumar
 *
 * @category   Saurav
 * @package    Avinash_EmailSender
 * @author     Saurav Kumar
 */
namespace Avinash\EmailSender\Model\ResourceModel\SendCount;

/**
 * SendCount Collection Class
 */
class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'entity_id';

    /**
     * Initialize resource model
     *
     * @return void
     */
    public function _construct()
    {
        $this->_init(
            \Avinash\EmailSender\Model\SendCount::class,
            \Avinash\EmailSender\Model\ResourceModel\SendCount::class
        );
        $this->_map['fields']['entity_id'] = 'main_table.entity_id';
    }
}

