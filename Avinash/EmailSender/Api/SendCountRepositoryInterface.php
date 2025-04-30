<?php
/**
 * Saurav Kumar
 *
 * @category   Saurav
 * @package    Avinash_EmailSender
 * @author     Saurav Kumar
 */
namespace Avinash\EmailSender\Api;

/**
 * SendCountRepository Repository Interface
 */
interface SendCountRepositoryInterface
{
    /**
     * Get by id
     *
     * @param int $id
     * @return Avinash\EmailSender\Model\SendCount
     */
    public function getById($id);
    /**
     * Save
     *
     * @param Avinash\EmailSender\Model\SendCount $subject
     * @return Avinash\EmailSender\Model\SendCount
     */
    public function save(\Avinash\EmailSender\Model\SendCount $subject);
    /**
     * Get list
     *
     * @param Magento\Framework\Api\SearchCriteriaInterface $creteria
     * @return Magento\Framework\Api\SearchResults
     */
    public function getList(\Magento\Framework\Api\SearchCriteriaInterface $creteria);
    /**
     * Delete
     *
     * @param Avinash\EmailSender\Model\SendCount $subject
     * @return boolean
     */
    public function delete(\Avinash\EmailSender\Model\SendCount $subject);
    /**
     * Delete by id
     *
     * @param int $id
     * @return boolean
     */
    public function deleteById($id);
}

