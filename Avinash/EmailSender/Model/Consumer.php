<?php
/**
 * Saurav Kumar.
 *
 * @category  Saurav Kumar
 * @package   Avinash_EmailSender
 * @author    Saurav Kumar
 */
namespace Avinash\EmailSender\Model;

use Magento\Framework\MessageQueue\ConsumerConfiguration;
use Magento\Framework\App\Config\ScopeConfigInterface;

/**
 * Class Consumer used to process OperationInterface messages.
 */
class Consumer extends ConsumerConfiguration
{
    const CONSUMER_NAME = "emailsender.massmail";

    const QUEUE_NAME = "emailsender.massmail";

    /**
     * @var \Avinash\EmailSender\Logger\Logger
     */
    protected $logger;

    /**
     * @var \Avinash\EmailSender\Helper\Email
     */
    protected $jsonHelper;

    /**
     * @var \Magento\Framework\Json\Helper\Data
     */
    protected $_emailHelper;

    /**
     * __construct function
     *
     * @param \Avinash\EmailSender\Logger\Logger $logger
     * @param \Avinash\EmailSender\Helper\Data $emailHelper
     * @param \Magento\Framework\Json\Helper\Data $jsonHelper
     */
    public function __construct(
        \Avinash\EmailSender\Logger\Logger $logger,
        \Avinash\EmailSender\Helper\Data $emailHelper,
        \Magento\Framework\Json\Helper\Data $jsonHelper
    ) {
        $this->logger = $logger;
        $this->jsonHelper = $jsonHelper;
        $this->_emailHelper = $emailHelper; 
    }

    /**
     * consumer process start
     * @param string $request
     * @return string
     */
    public function process($request)
    {   
        try {
            $data = $this->jsonHelper->jsonDecode($request, true);
            $this->_emailHelper->SendMail($data);
        } catch (\Exception $e) {
            $this->logger->error($e->getMessage());
        }

    }
}