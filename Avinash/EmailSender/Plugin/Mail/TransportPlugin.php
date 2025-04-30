<?php
/**
 * Saurav Kumar.
 *
 * @category  Saurav Kumar
 * @package   Avinash_EmailSender
 * @author    Saurav Kumar
 */
namespace Avinash\EmailSender\Plugin\Mail;

class TransportPlugin extends \Magento\Email\Model\Mail\TransportInterfacePlugin
{
    /**
     * @var \Avinash\EmailSender\Helper\Data
     */
    private $dataHelper;

    /**
     * @var \Avinash\EmailSender\Model\Store
     */
    private $storeModel;

    /**
     * @var \Avinash\EmailSender\Logger\Logger
     */
    private $logger;

    /**
     * @var \Magento\Framework\App\RequestInterface
     */
    private $request;

    /**
     * @var \Magento\Framework\App\Config\ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * __construct function
     *
     * @param \Avinash\EmailSender\Logger\Logger $logger
     * @param \Avinash\EmailSender\Helper\Data $dataHelper
     * @param \Avinash\EmailSender\Model\Store $storeModel
     * @param \Magento\Framework\App\RequestInterface $request
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        \Avinash\EmailSender\Logger\Logger $logger,
        \Avinash\EmailSender\Helper\Data $dataHelper,
        \Avinash\EmailSender\Model\Store $storeModel,
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->request = $request;
        $this->dataHelper = $dataHelper;
        $this->storeModel = $storeModel;
        $this->scopeConfig = $scopeConfig;
        parent::__construct($scopeConfig);
    }
    /**
     * Around SendMessage
     *
     * @param \Magento\Framework\Mail\TransportInterface $subject
     * @param \Closure $proceed
     * @throws \Magento\Framework\Exception\MailException
     * @throws \Zend_Mail_Exception
     */
    public function aroundSendMessage(
        \Magento\Framework\Mail\TransportInterface $subject,
        \Closure $proceed
    ) {
        try {
            if ($this->dataHelper->isEnable()) {
                if (method_exists($subject, 'getStoreId')) {
                    $this->storeModel->setStoreId($subject->getStoreId());
                }
                $message = $subject->getMessage();
                $data = $this->request->getPostValue();
                $smtp = new \Avinash\EmailSender\Model\Smtp($this->dataHelper, $this->storeModel);
                $smtp->sendSmtpMessage($message, $data);
            } else {
                if (!$this->scopeConfig->isSetFlag('system/smtp/disable', ScopeInterface::SCOPE_STORE)) {
                    $proceed();
                }
            }
        } catch (\Magento\Framework\Exception\MailException $e) {
            $this->logger->error("Plugin: ".$e->getMessage());
            throw new \Magento\Framework\Exception\MailException(
                new \Magento\Framework\Phrase($e->getMessage()),
                $e
            );
        }
    }
}
