<?php
/**
 * Saurav Kumar.
 *
 * @category  Saurav Kumar
 * @package   Avinash_EmailSender
 * @author    Saurav Kumar
 */
namespace Avinash\EmailSender\Controller\Adminhtml\Log;

use Avinash\EmailSender\Helper\Data;
use Magento\Backend\App\Action\Context;
use Magento\Backend\Controller\Adminhtml\System;
use Magento\Framework\App\Response\Http\FileFactory;

class Delete extends System
{
    /**
     * @var FileFactory
     */
    protected $fileFactory;

    /**
     * @var Data
     */
    protected $logDataHelper;

    /**
     * @var int
     */
    protected $pageParam;

    public function __construct(
        Context $context,
        Data $logDataHelper,
        FileFactory $fileFactory
    ) {
        $this->fileFactory = $fileFactory;
        $this->logDataHelper = $logDataHelper;
        parent::__construct($context);
    }

    /**
     * Execute
     *
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute()
    {
        $postData = $this->getRequest()->getParams();
        $this->pageParam = ["page"=>1];
        if (isset($postData['page']) && $postData['page']!='') {
            $this->pageParam = ["page"=>$postData['page']];
        }
        $file = str_replace('_', '/', $postData[0]);
        $path = $this->logDataHelper->getRootDirectory();
        $this->getFilePathWithFile($file, $path);
    }

    /**
     * Clear Log
     *
     * @param string $fileName
     * @param string $filePath
     * @return void
     */
    public function getFilePathWithFile($fileName, $filePath)
    {
        $file = $filePath . $fileName;
        // @codingStandardsIgnoreStart
        $fp = fopen($file, "r+");
        ftruncate($fp, 0);
        fclose($fp);
        // @codingStandardsIgnoreEnd
        $this->messageManager->addSuccessMessage(__("Delete File Content."));
        $this->_redirect('emailsender/log/index', $this->pageParam);
    }

    /**
     * Is Allowed
     *
     * @return bool
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed("Avinash_EmailSender:log");
    }
}
