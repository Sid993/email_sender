<?php
/**
 * Saurav Kumar
 *
 * @category   Saurav
 * @package    Avinash_EmailSender
 * @author     Saurav Kumar
 */
namespace Avinash\EmailSender\Block\Adminhtml;

use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template\Context;

class EmailSender extends \Magento\Framework\View\Element\Template
{
    public const REPORTFILE_VIEW = "emailsender/log/view";
    public const REPORTFILE_DELETE = "emailsender/log/delete";
    public const REPORTFILE_DOWNLOAD = "emailsender/log/getfile";

    /**
     * @var FormKey
     */
    protected $formKey;

    /**
     * @var \Avinash\EmailSender\Helper\Data
     */
    protected $helper;

    /**
     * Construct function
     *
     * @param FormKey $formKey
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        FormKey $formKey,
        Context $context,
        \Avinash\EmailSender\Helper\Data $helper,
        array $data = []
    ) {
        $this->helper = $helper;
        $this->formKey = $formKey;
        parent::__construct($context, $data);
    }

    /**
     * Get Form Key function
     *
     * @return string
     */
    public function getFormKey()
    {
        return $this->formKey->getFormKey();
    }

    /**
     * Get Log Files
     *
     * @return array
     */
    public function getLogFiles()
    {
        $params = $this->getRequest()->getParams();
        return $this->helper->getAllFileAndDirectory($params);
    }

    /**
     * Download Log Files
     *
     * @param string $fileName
     * @return array
     */
    public function downloadLogFiles($fileName)
    {
        return $this->getUrl(self::REPORTFILE_DOWNLOAD, [$fileName]);
    }

    /**
     * Preview Log Files
     *
     * @param string $fileName
     * @return array
     */
    public function previewLogFile($fileName)
    {
        return $this->getUrl(self::REPORTFILE_VIEW, [$fileName]);
    }

    /**
     * Delete Log Files
     *
     * @param string $fileName
     * @return array
     */
    public function deleteLogFile($fileName)
    {
        return $this->getUrl(self::REPORTFILE_DELETE, [$fileName]);
    }

    /**
     * Get File Path
     *
     * @return string
     */
    public function getFilePath()
    {
        $path = $this->helper->getPath();
        return $path;
    }

    /**
     * Get Root Dir
     *
     * @return string
     */
    public function getRootDir()
    {
        $path = $this->helper->getRootDirectory();
        return $path;
    }

    /**
     * Get File Name
     *
     * @return string
     */
    public function getFileName()
    {
        $params = $this->getRequest()->getParams();
        return $params[0];
    }
}
