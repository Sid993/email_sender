<?php
/**
 * Saurav Kumar.
 *
 * @category  Saurav Kumar
 * @package   Avinash_EmailSender
 * @author    Saurav Kumar
 */
namespace Avinash\EmailSender\Controller\Adminhtml\Email;

use Magento\Framework\File\Csv;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Framework\Controller\Result\JsonFactory;

class Send extends Action
{
    public const ADMIN_RESOURCE = "Avinash_EmailSender:emailSender";

    /**
     * @var StateInterface
     */
    protected $inlineTranslation;

    /**
     * @var JsonFactory
     */
    protected $resultJsonFactory;

    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;

    /**
     * @var StoreManagerInterface
     */
    protected $storeManager;

    /**
     * @var \Avinash\EmailSender\Logger\Logger
     */
    protected $logger;

    /**
     * @var Csv
     */
    protected $csv;

    /**
     * @var DirectoryList
     */
    protected $directoryList;

    /**
     * @var \Avinash\EmailSender\Api\Data\SendCountInterfaceFactory
     */
    protected $sendCountFactory;

    /**
     * @var \Avinash\EmailSender\Api\SendCountRepositoryInterfaceFactory
     */
    protected $sendCountRepo;

    /**
     * @var \Avinash\EmailSender\Model\ResourceModel\SendCount\CollectionFactory
     */
    protected $collection;

    /**
     * @var \Magento\Framework\MessageQueue\PublisherInterface
     */
    protected $queueConsumer;

    /**
     * __construct function
     *
     * @param Csv $csv
     * @param Context $context
     * @param DirectoryList $directoryList
     * @param JsonFactory $resultJsonFactory
     * @param StateInterface $inlineTranslation
     * @param TransportBuilder $transportBuilder
     * @param StoreManagerInterface $storeManager
     * @param \Avinash\EmailSender\Logger\Logger $logger
     * @param \Magento\Framework\MessageQueue\PublisherInterface $queueConsumer
     * @param \Avinash\EmailSender\Api\Data\SendCountInterfaceFactory $sendCountFactory
     * @param \Avinash\EmailSender\Api\SendCountRepositoryInterfaceFactory $sendCountRepo
     * @param \Avinash\EmailSender\Model\ResourceModel\SendCount\CollectionFactory $collection
     */
    public function __construct(
        Csv $csv,
        Context $context,
        DirectoryList $directoryList,
        JsonFactory $resultJsonFactory,
        StateInterface $inlineTranslation,
        TransportBuilder $transportBuilder,
        StoreManagerInterface $storeManager,
        \Avinash\EmailSender\Logger\Logger $logger,
        \Magento\Framework\MessageQueue\PublisherInterface $queueConsumer,
        \Avinash\EmailSender\Api\Data\SendCountInterfaceFactory $sendCountFactory,
        \Avinash\EmailSender\Api\SendCountRepositoryInterfaceFactory $sendCountRepo,
        \Avinash\EmailSender\Model\ResourceModel\SendCount\CollectionFactory $collection
    ) {
        parent::__construct($context);
        $this->csv = $csv;
        $this->logger = $logger;
        $this->collection = $collection;
        $this->storeManager = $storeManager;
        $this->sendCountRepo = $sendCountRepo;
        $this->directoryList = $directoryList;
        $this->queueConsumer = $queueConsumer;
        $this->sendCountFactory = $sendCountFactory;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        $this->resultJsonFactory = $resultJsonFactory;
    }

    /**
     * Execute the action
     *
     * @return PageFactory
     */
    public function execute()
    {
        $result = $this->resultJsonFactory->create();
        $response = ['success' => false, 'message' => '', 'totalCount' => 0, 'remainingCount' => 0];
        $sendCount = 0;
        $data = $this->getRequest()->getParams();
        $csvData = $this->readCsv($data['file_name']);
        if ($totalCount = count($csvData)) {
            $emailData = array_column($csvData, '0');
            $data['send_to'] = $emailData;
            $sendCount = $data['send_limit'];

            $sendData = $this->collection->create()
                    ->addFieldToFilter("file_name", ["eq" => $data['file_name']])->getFirstItem();
            $totalSendCount = 0;
            if ($sendData->getEntityId()) {
                $totalSendCount = $sendData->getSendCount();
                $sendCount = $data['send_limit'] + $totalSendCount;
            }
            if ($totalCount != $sendData->getSendCount()) {
                $data['send_to'] = array_slice($data['send_to'], $totalSendCount, $data['send_limit']);

                //try {
                //     $this->queueConsumer->publish("emailsender.massmail", json_encode($data));
                //     $sendCountModal = $this->sendCountFactory->create();
                //     if ($sendData->getEntityId()) {
                //         $sendData->setSendCount($sendCount)->save();
                //     } else {
                //         $countData = [
                //             "send_count" => $sendCount,
                //             "file_name" => $data['file_name']
                //         ];
                //         $sendCountModal->setData($countData);
                //         $this->sendCountRepo->create()->save($sendCountModal);
                //     }
                    
                //     $response = [
                //         'success' => true,
                //         'message' => __('Email sent successfully.'),
                //         'totalCount' => $totalCount,
                //         'remainingCount' => $totalCount - $sendCount
                //     ];
                // } catch (\Exception $e) {
                //     $this->logger->error("queue : ".$e->getMessage());
                //     $sendCount = $sendCount - $data['send_limit'];
                //     $response = [
                //         'success' => false,
                //         'message' => __('Email not sent.'),
                //         'totalCount' => $totalCount,
                //         'remainingCount' => $totalCount - $sendCount
                //     ];
                // }

                $isSent = $this->SendMail($data);
                if ($isSent) {
                    $sendCountModal = $this->sendCountFactory->create();
                    if ($sendData->getEntityId()) {
                        $sendData->setSendCount($sendCount)->save();
                    } else {
                        $countData = [
                            "send_count" => $sendCount,
                            "file_name" => $data['file_name']
                        ];
                        $sendCountModal->setData($countData);
                        $this->sendCountRepo->create()->save($sendCountModal);
                    }
                    
                    $this->logger->info("Email Sent To : ".json_encode($data['send_to']));
                    $response = [
                        'success' => true,
                        'message' => __('Email sent successfully.'),
                        'totalCount' => $totalCount,
                        'remainingCount' => $totalCount - $sendCount
                    ];
                } else {
                    $this->logger->info("Email Sent Failed To : ".json_encode($data['send_to']));
                    $sendCount = $sendCount - $data['send_limit'];
                    $response = [
                        'success' => false,
                        'message' => __('Email not sent.'),
                        'totalCount' => $totalCount,
                        'remainingCount' => $totalCount - $sendCount
                    ];
                }
            } else {
                $response = [
                    'success' => false,
                    'message' => __('Datafile completed.'),
                    'totalCount' => $totalCount,
                    'remainingCount' => 0
                ];
            }
        } else {
            if (isset($data['send_to']) && $data['send_to'] != "") {
                $data['send_to'] = explode(",", $data['send_to']);
                $isSent = $this->SendMail($data);
                if ($isSent) {
                    $response = [
                        'success' => true,
                        'message' => __('Email sent successfully.'),
                        'totalCount' => count($data['send_to']),
                        'remainingCount' => 0
                    ];
                } else {
                    $response = [
                        'success' => false,
                        'message' => __('Email not sent.'),
                        'totalCount' => count($data['send_to']),
                        'remainingCount' => 0
                    ];
                }
            } else {
                $response = [
                    'success' => false,
                    'message' => __("Please add email id."),
                    'totalCount' => 0,
                    'remainingCount' => 0
                ];
            }
        }
        return $result->setData($response);
    }

    /**
     * Read CSV function
     *
     * @param string $fileName
     * @return array
     */
    public function readCsv($fileName)
    {
        // Get the path to the CSV file
        $path = $this->directoryList->getPath(DirectoryList::MEDIA) . '/email/' . $fileName;

        // Check if the file exists
        if (!file_exists($path)) {
            $this->logger->info('File not found: '.$path);
        }

        // Read the CSV file
        try {
            $data = $this->csv->getData($path);
        } catch (\Exception $e) {
            $data = [];
            $this->logger->info('Error reading CSV file: '.$e->getMessage());
        }
        return $data; // Returns an array of CSV rows
    }

    /**
     * SendMail function
     *
     * @param array $data
     * @return bool
     */
    private function SendMail($data)
    {
        try {
            $from = ['name' => $data['from_name'], 'email' => $data['from_email']];
            $vars = [
                'subject' => $data['subject_line'],
                'message' => $data['message']
            ];

            if (isset($data['send_to'])) {
                $to = $data['send_to'];
            }

            $templateId = 'bulk_email_template';
            $this->inlineTranslation->suspend();
            foreach ($to as $email) {
                $transport = $this->transportBuilder->setTemplateIdentifier($templateId)
                ->setTemplateOptions([
                    'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                    'store' => 1,
                ])
                ->setTemplateVars($vars)
                ->setFrom($from)
                ->addTo($email)
                ->getTransport();
                
                $transport->sendMessage();
            }
            $this->inlineTranslation->resume();
            return true;
        } catch (\Magento\Framework\Exception\MailException $e) {
            $this->logger->error("controller : ".$e->getMessage());
            // $this->inlineTranslation->resume();
            return false;
        }
    }

    /**
     * get Image Path
     *
     * @return void
     */
    public function getMediaPath()
    {
        return $this->storeManager->getStore()->getBaseUrl().'pub/media/email';
    }

    /**
     * Is Allowed
     *
     * @return boolean
     */
    protected function _isAllowed()
    {
        return $this->_authorization->isAllowed(self::ADMIN_RESOURCE);
    }
}
