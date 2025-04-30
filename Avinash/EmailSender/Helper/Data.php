<?php
/**
 * Saurav Kumar.
 *
 * @category  Saurav Kumar
 * @package   Avinash_EmailSender
 * @author    Saurav Kumar
 */
namespace Avinash\EmailSender\Helper;

use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    /**
     * @var \Magento\Framework\App\Filesystem\DirectoryList
     */
    protected $directoryList;

    /**
     * @var StateInterface
     */
    protected $inlineTranslation;

    /**
     * @var TransportBuilder
     */
    protected $transportBuilder;

    /**
     * @var string
     */
    protected $rootPath = "";

    /**
     * @var \Avinash\EmailSender\Logger\Logger
     */
    protected $logger;

    /**
     * __construct function
     *
     * @param StateInterface $inlineTranslation
     * @param TransportBuilder $transportBuilder
     * @param \Avinash\EmailSender\Logger\Logger $logger
     * @param \Magento\Framework\App\Helper\Context $context
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     */
    public function __construct(
        StateInterface $inlineTranslation,
        TransportBuilder $transportBuilder,
        \Avinash\EmailSender\Logger\Logger $logger,
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList
    ) {
        $this->logger = $logger;
        $this->directoryList = $directoryList;
        $this->transportBuilder = $transportBuilder;
        $this->inlineTranslation = $inlineTranslation;
        parent::__construct($context);
    }

    /**
     * IsEnable
     *
     * @return boolean
     */
    public function isEnable()
    {
        return $this->scopeConfig->getValue('smtp/general/enable');
    }

    /**
     * Get Path 
     *
     * @return string
     */
    public function getPath()
    {
        $rootPath = $this->directoryList->getRoot();
        $this->rootPath = $rootPath;
        $logDir = 'log';
        $path = $rootPath . DIRECTORY_SEPARATOR . 'var' . DIRECTORY_SEPARATOR . $logDir;
        return $path;
    }

    /**
     * Get All Log Files
     *
     * @param array $postData
     * @return array
     */
    public function getAllFileAndDirectory($postData)
    {
        $listFiles = $this->getDirContents($this->getPath());
        $page = $postData['page'] ?? 1;
        $pageSize = 10;
        
        $totalRecords = count($listFiles);
        $totalPages = ceil($totalRecords/$pageSize);
        $records['totalRecords'] = $totalRecords;
        $records['totalPages'] = $totalPages;
        if ($page > $totalPages) {
            $page = $totalPages;
        }
        if ($page < 1) {
            $page = 1;
        }
        $records['page'] = $page;
        $offset = ($page - 1) * $pageSize;
        $listFiles = array_slice($listFiles, $offset, $pageSize);
        $records['items'] = $listFiles;
        return $records;
    }

    /**
     * Get Directory Content
     *
     * @return array
     */
    public function getDirContents($dir, &$results = [])
    {
        if(!file_exists($dir)) {
            return $results;
        }
        $files = scandir($dir);
    
        foreach ($files as $key => $value) {
            $path = realpath($dir . DIRECTORY_SEPARATOR . $value);
            if (!is_dir($path)) {
                $subPath = substr($dir, strlen($this->rootPath));
                $fileSubPath = $subPath.DIRECTORY_SEPARATOR.$value;
                $fileSubPath = str_replace('/', '_', $fileSubPath);
                $results[] = [
                    "path"=>$path,
                    "file"=>$value,
                    "name"=>$value,
                    "filesize"=> $this->fileSizeToReadableString((filesize($path))),
                    "modTime"=> filemtime($path),
                    "modTimeLong"=> date("F d Y H:i:s.", filemtime($path)),
                    "subpath" => $subPath,
                    "filesubpath"=> $fileSubPath
                ];
            } elseif ($value != "." && $value != "..") {
                $this->getDirContents($path, $results);
            }
        }
        usort($results, function ($item1, $item2) {
            return $item2['modTime'] <=> $item1['modTime'];
        });

        return $results;
    }

    /**
     * File Size Read
     *
     * @param $bytes
     * @param int $precision
     * @return string
     */
    protected function fileSizeToReadableString($bytes, $precision = 2)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Get Root Directory function
     *
     * @return string
     */
    public function getRootDirectory()
    {
        return $this->directoryList->getRoot();
    }

    /**
     * SendMail function
     *
     * @param array $data
     * @return bool
     */
    public function SendMail($data)
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

            foreach ($to as $email) {
                $templateId = 'bulk_email_template';
                $this->inlineTranslation->suspend();
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
                $this->inlineTranslation->resume();
            }
            $this->logger->info("Email Sent To : ".json_encode($data['send_to']));
        } catch (\Magento\Framework\Exception\MailException $e) {
            $this->logger->error("controller : ".$e->getMessage());
            $this->inlineTranslation->resume();
            return false;
        }
    }
}
