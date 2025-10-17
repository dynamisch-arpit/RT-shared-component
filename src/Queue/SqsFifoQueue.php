<?php

namespace RT\SharedComponents\Queue;

use Aws\Sqs\SqsClient;
use Aws\Exception\AwsException;

class SqsFifoQueue
{
    private $sqsClient;
    private $queueUrl;
    private $queueName;
    private $config;

    /**
     * Create a new SQS FIFO Queue instance
     *
     * @param array $config Configuration array
     *   - aws: AWS SDK configuration
     *   - sqs: SQS configuration
     *     - queue_name: Name of the queue (must end with .fifo)
     *     - queue_url: Optional queue URL if queue already exists
     *     - create_dlq: Whether to create a DLQ (default: false)
     *     - dlq_config: Configuration for DLQ (if create_dlq is true)
     *       - queue_name: Name of the DLQ (default: {queue_name}-dlq.fifo)
     *       - max_receive_count: Max receive count before moving to DLQ (default: 5)
     */
    public function __construct($config)
    {
        $this->config = $config;
        $this->sqsClient = new SqsClient($config['aws']);
        $this->queueName = $config['sqs']['queue_name'];
        $this->queueUrl = $config['sqs']['queue_url'] ?? '';
        // If queue URL is provided, use it directly
        if (!empty($this->queueUrl)) {
            // echo "Using pre-configured queue URL: " . $this->queueUrl . "\n";
        }
    }
    
    /**
     * Set queue name dynamically
     */
    public function setQueueName($queueName)
    {
        $this->queueName = $queueName;
        $this->queueUrl = ''; // Reset queue URL when name changes
    }

    /**
     * Create a FIFO queue with custom attributes
     */
    /**
     * Create a FIFO queue with optional DLQ
     *
     * @param array $customAttributes Additional queue attributes
     * @param array $dlqConfig Configuration for DLQ (overrides config from constructor)
     * @return string Queue URL
     */
    public function createQueue($customAttributes = [], $dlqConfig = null)
    {
        try {
            // Handle DLQ creation if enabled
            $dlqUrl = null;
            $shouldCreateDlq = $dlqConfig['enabled'] ?? $this->config['sqs']['create_dlq'] ?? false;
            
            if ($shouldCreateDlq) {
                $dlqUrl = $this->createDlq($dlqConfig);
            }

            // Default attributes for FIFO queue
            $defaultAttributes = [
                'FifoQueue' => 'true',
                'ContentBasedDeduplication' => 'true',
                'MessageRetentionPeriod' => '1209600', // 14 days
                'VisibilityTimeout' => '30',
                'ReceiveMessageWaitTimeSeconds' => '20'
            ];
            
            // Add redrive policy if DLQ is configured
            if ($dlqUrl) {
                $maxReceiveCount = $dlqConfig['max_receive_count'] ?? $this->config['sqs']['dlq_config']['max_receive_count'] ?? 5;
                $defaultAttributes['RedrivePolicy'] = json_encode([
                    'deadLetterTargetArn' => $dlqUrl,
                    'maxReceiveCount' => $maxReceiveCount
                ]);
            }
            
            // Ensure all attribute values are strings
            $stringAttributes = [];
            foreach (array_merge($defaultAttributes, $customAttributes) as $key => $value) {
                $stringAttributes[$key] = is_array($value) ? json_encode($value) : (string)$value;
            }
            
            // Ensure FifoQueue is always true (cannot be overridden)
            // Create the queue with the specified attributes
            $result = $this->sqsClient->createQueue([
                'QueueName' => $this->queueName,
                'Attributes' => $stringAttributes,
                'tags' => [
                    'CreatedBy' => 'SqsFifoQueue',
                    'Environment' => $this->config['sqs']['environment'] ?? 'development'
                ]
            ]);

            $this->queueUrl = $result['QueueUrl'];
            echo "Queue created successfully: " . $this->queueUrl . "\n";
            
            // Tag the queue with DLQ information if applicable
            if ($dlqUrl) {
                $this->sqsClient->tagQueue([
                    'QueueUrl' => $this->queueUrl,
                    'Tags' => [
                        'DLQ' => 'true',
                        'DLQ_URL' => $dlqUrl
                    ]
                ]);
            }
            
            return $this->queueUrl;

        } catch (AwsException $e) {
            echo "Error creating queue: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }

    /**
     * Get queue URL if it exists
     */
    public function getQueueUrl()
    {
        try {
            $result = $this->sqsClient->getQueueUrl([
                'QueueName' => $this->queueName
            ]);
            
            $this->queueUrl = $result['QueueUrl'];
            return $this->queueUrl;

        } catch (AwsException $e) {
            echo "Queue not found: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }

    /**
     * Send a message to the FIFO queue with configuration options
     */
    public function sendMessage($messageBody, $messageGroupId, $messageDeduplicationId = null, $messageConfig = [])
    {
        if (!$this->queueUrl) {
            echo "Queue URL not set. Please create or get the queue first.\n";
            return false;
        }

        try {
            $params = [
                'QueueUrl' => $this->queueUrl,
                'MessageBody' => $messageBody,
                'MessageGroupId' => $messageGroupId
            ];

            // Add deduplication ID if provided (optional if ContentBasedDeduplication is enabled)
            if ($messageDeduplicationId) {
                $params['MessageDeduplicationId'] = $messageDeduplicationId;
            }

            // Add message configuration options
            if (!empty($messageConfig['DelaySeconds'])) {
                $params['DelaySeconds'] = (int)$messageConfig['DelaySeconds'];
            }

            // Add message attributes if provided
            if (!empty($messageConfig['MessageAttributes'])) {
                $params['MessageAttributes'] = $messageConfig['MessageAttributes'];
            }
            // echo "Sending message to SQS FIFO queue: " . json_encode($params) . "\n";
            $result = $this->sqsClient->sendMessage($params);
            // print_r($result);

            // echo "Message sent successfully. MessageId: " . $result['MessageId'] . "\n";
            return $result['MessageId'];

        } catch (AwsException $e) {
            echo "Error sending message: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }

    /**
     * Send multiple messages in a batch
     */
    public function sendMessageBatch($messages)
    {
        if (!$this->queueUrl) {
            echo "Queue URL not set. Please create or get the queue first.\n";
            return false;
        }

        try {
            $entries = [];
            foreach ($messages as $index => $message) {
                $entry = [
                    'Id' => (string)$index,
                    'MessageBody' => $message['body'],
                    'MessageGroupId' => $message['groupId']
                ];

                if (isset($message['deduplicationId'])) {
                    $entry['MessageDeduplicationId'] = $message['deduplicationId'];
                }

                $entries[] = $entry;
            }

            $result = $this->sqsClient->sendMessageBatch([
                'QueueUrl' => $this->queueUrl,
                'Entries' => $entries
            ]);

            echo "Batch sent successfully. Successful: " . count($result['Successful']) . "\n";
            return $result;

        } catch (AwsException $e) {
            echo "Error sending batch: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }

    /**
     * Receive messages from the FIFO queue
     */
    public function receiveMessages($maxMessages = 1, $waitTimeSeconds = 20)
    {
        if (!$this->queueUrl) {
            echo "Queue URL not set. Please create or get the queue first.\n";
            return false;
        }
        try {
            $result = $this->sqsClient->receiveMessage([
                'QueueUrl' => $this->queueUrl,
                'MaxNumberOfMessages' => $maxMessages,
                'WaitTimeSeconds' => $waitTimeSeconds,
                'AttributeNames' => ['All'],
                'MessageAttributeNames' => ['All']
            ]);

            if (isset($result['Messages'])) {
                echo "Received " . count($result['Messages']) . " message(s)\n";
                return $result['Messages'];
            } else {
                echo "No messages received\n";
                return [];
            }

        } catch (AwsException $e) {
            echo "Error receiving messages: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }

    /**
     * Delete a message from the queue
     */
    public function deleteMessage($receiptHandle)
    {
        if (!$this->queueUrl) {
            echo "Queue URL not set. Please create or get the queue first.\n";
            return false;
        }

        try {
            $this->sqsClient->deleteMessage([
                'QueueUrl' => $this->queueUrl,
                'ReceiptHandle' => $receiptHandle
            ]);

            echo "Message deleted successfully\n";
            return true;

        } catch (AwsException $e) {
            echo "Error deleting message: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }

    /**
     * Get queue attributes
     */
    public function getQueueAttributes()
    {
        if (!$this->queueUrl) {
            echo "Queue URL not set. Please create or get the queue first.\n";
            return false;
        }

        try {
            $result = $this->sqsClient->getQueueAttributes([
                'QueueUrl' => $this->queueUrl,
                'AttributeNames' => ['All']
            ]);

            return $result['Attributes'];

        } catch (AwsException $e) {
            echo "Error getting queue attributes: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }

    /**
     * Delete the queue
     */
    /**
     * Create a Dead Letter Queue for this queue
     * 
     * @param array $dlqConfig DLQ configuration
     * @return string DLQ URL
     */
    private function createDlq($dlqConfig = [])
    {
        $dlqName = $dlqConfig['queue_name'] ?? $this->queueName . '-dlq.fifo';
        
        $dlq = new self([
            'aws' => $this->config['aws'],
            'sqs' => [
                'queue_name' => $dlqName,
                'create_dlq' => false // Prevent recursive DLQ creation
            ]
        ]);
        
        $dlqUrl = $dlq->createQueue([
            'MessageRetentionPeriod' => '1209600', // 14 days
            'FifoQueue' => 'true',
            'ContentBasedDeduplication' => 'true'
        ]);
        
        echo "Created DLQ: $dlqUrl\n";
        return $dlqUrl;
    }
    
    /**
     * Get the DLQ URL for this queue if it exists
     * 
     * @return string|null DLQ URL or null if not found
     */
    public function getDlqUrl()
    {
        try {
            $tags = $this->sqsClient->listQueueTags([
                'QueueUrl' => $this->getQueueUrl()
            ]);
            
            return $tags['Tags']['DLQ_URL'] ?? null;
        } catch (AwsException $e) {
            return null;
        }
    }
    
    /**
     * Get the DLQ instance for this queue
     * 
     * @return SqsFifoQueue|null DLQ instance or null if not configured
     */
    public function getDlq()
    {
        $dlqUrl = $this->getDlqUrl();
        if (!$dlqUrl) {
            return null;
        }
        
        return new self([
            'aws' => $this->config['aws'],
            'sqs' => ['queue_url' => $dlqUrl]
        ]);
    }
    
    /**
     * Delete this queue and its DLQ if it exists
     * 
     * @param bool $deleteDlq Whether to delete the DLQ as well
     * @return bool True if successful
     */
    public function deleteQueue($deleteDlq = true)
    {
        if (!$this->queueUrl) {
            echo "Queue URL not set. Please create or get the queue first.\n";
            return false;
        }

        try {
            // Optionally delete DLQ first
            if ($deleteDlq && ($dlq = $this->getDlq())) {
                $dlq->deleteQueue(false);
            }
            
            $this->sqsClient->deleteQueue([
                'QueueUrl' => $this->queueUrl
            ]);

            echo "Queue deleted successfully\n";
            return true;

        } catch (AwsException $e) {
            echo "Error deleting queue: " . $e->getAwsErrorMessage() . "\n";
            return false;
        }
    }
}
