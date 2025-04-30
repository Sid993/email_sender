<?php
/**
 * Saurav Kumar.
 *
 * @category  Saurav Kumar
 * @package   Avinash_EmailSender
 * @author    Saurav Kumar
 */
namespace Avinash\EmailSender\Model;

use Laminas\Mail\Message;
use Laminas\Mail\Transport\Smtp as SmtpTransport;
use Laminas\Mail\Transport\SmtpOptions;

class Smtp
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
     * @param \Avinash\EmailSender\Helper\Data $dataHelper
     * @param \Avinash\EmailSender\Model\Store $storeModel
     */
    public function __construct(
        \Avinash\EmailSender\Helper\Data $dataHelper,
        \Avinash\EmailSender\Model\Store $storeModel
    ) {
        $this->dataHelper = $dataHelper;
        $this->storeModel = $storeModel;
    }

    /**
     * Set DataHelper
     *
     * @param \Avinash\EmailSender\Helper\Data $dataHelper
     * @return Smtp
     */
    public function setDataHelper(\Avinash\EmailSender\Helper\Data $dataHelper)
    {
        $this->dataHelper = $dataHelper;
        return $this;
    }

    /**
     * Set StoreModel
     *
     * @param \Avinash\EmailSender\Model\Store $storeModel
     * @return Smtp
     */
    public function setStoreModel(\Avinash\EmailSender\Model\Store $storeModel)
    {
        $this->storeModel = $storeModel;
        return $this;
    }

    /**
     * Send SmtpMessage
     *
     * @param \Magento\Framework\Mail\MessageInterface $message
     * @param array $data
     * @throws \Magento\Framework\Exception\MailException
     */
    public function sendSmtpMessage(
        \Magento\Framework\Mail\EmailMessage $message,
        $data
    ) {
        $message = Message::fromString($message->getRawMessage());
        if (empty($message->getFrom()) || count($message->getFrom()) == 0) {
            $result = $this->storeModel->getFrom();
            $message->setFrom($result['email'], $result['name']);
        }
        //set config
        $options   = new SmtpOptions([
            'name' => "Admin",
            'host' => $data['server'],
            'port' => $data['port']
        ]);
        
        $connectionConfig = [];
        $auth = "login";
        if ($auth != 'none') {
            $options->setConnectionClass($auth);
            $options->setConnectionTimeLimit(3600);
            $connectionConfig = [
                'username' => $data['username'],
                'password' => $data['password']
            ];
        }
        $connectionConfig['ssl'] = $data['tls'] ? "tls" : "ssl";

        if (!empty($connectionConfig)) {
            $options->setConnectionConfig($connectionConfig);
        }
        try {
            $transport = new SmtpTransport();
            $transport->setOptions($options);
            $transport->send($message);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Exception\MailException(
                new \Magento\Framework\Phrase($e->getMessage()),
                $e
            );
        }
    }
}
