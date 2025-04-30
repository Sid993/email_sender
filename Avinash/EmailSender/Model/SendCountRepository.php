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
 * SendCountRepository Repo Class
 */
class SendCountRepository implements \Avinash\EmailSender\Api\SendCountRepositoryInterface
{
    protected $modelFactory = null;

    protected $collectionFactory = null;

    /**
     * Initialize
     *
     * @param Avinash\EmailSender\Model\SendCountFactory $modelFactory
     * @param Avinash\EmailSender\Model\ResourceModel\SendCount\CollectionFactory
     * $collectionFactory
     */
    public function __construct(\Avinash\EmailSender\Model\SendCountFactory $modelFactory, \Avinash\EmailSender\Model\ResourceModel\SendCount\CollectionFactory $collectionFactory)
    {
        $this->modelFactory = $modelFactory;
        $this->collectionFactory = $collectionFactory;
    }

    /**
     * Get by id
     *
     * @param int $id
     * @return Avinash\EmailSender\Model\SendCount
     */
    public function getById($id)
    {
        $model = $this->modelFactory->create()->load($id);
        if (!$model->getId()) {
            throw new \Magento\Framework\Exception\NoSuchEntityException(
                __('The data with the "%1" ID doesn\'t exist.', $id)
            );
        }
        return $model;
    }

    /**
     * Save
     *
     * @param Avinash\EmailSender\Model\SendCount $subject
     * @return Avinash\EmailSender\Model\SendCount
     */
    public function save(\Avinash\EmailSender\Model\SendCount $subject)
    {
        try {
            $subject->save();
        } catch (\Exception $exception) {
             throw new \Magento\Framework\Exception\CouldNotSaveException(__($exception->getMessage()));
        }
        return $subject;
    }

    /**
     * Get list
     *
     * @param Magento\Framework\Api\SearchCriteriaInterface $creteria
     * @return Magento\Framework\Api\SearchResults
     */
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $creteria)
    {
        $collection = $this->collectionFactory->create();
        return $collection;
    }

    /**
     * Delete
     *
     * @param Avinash\EmailSender\Model\SendCount $subject
     * @return boolean
     */
    public function delete(\Avinash\EmailSender\Model\SendCount $subject)
    {
        try {
            $subject->delete();
        } catch (\Exception $exception) {
            throw new \Magento\Framework\Exception\CouldNotDeleteException(__($exception->getMessage()));
        }
        return true;
    }

    /**
     * Delete by id
     *
     * @param int $id
     * @return boolean
     */
    public function deleteById($id)
    {
        return $this->delete($this->getById($id));
    }
}

