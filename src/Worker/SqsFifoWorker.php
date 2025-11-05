<?php
namespace RT\SharedComponents\Worker;

use RT\SharedComponents\Queue\SqsFifoQueue;
use Aws\Exception\AwsException;

class SqsFifoWorker
{
    /**
     * @var SqsFifoQueue
     */
    private $queue;
    
    /**
     * @var array
     */
    private $config;
    
    /**
     * Default worker configuration
     * @var array
     */
    private $defaultConfig = [
        // Polling configuration
        'polling_type' => 'long',      // 'long' or 'short' polling
        'max_messages' => 10,          // Max messages per batch
        'wait_time' => 20,             // Wait time for long polling (seconds)
        'visibility_timeout' => 30,     // Message visibility timeout
        'concurrency' => 1,            // Max concurrent message processing
        
        // Retry configuration
        'max_retries' => 3,            // Max retry attempts for failed messages
        'retry_delay' => 0,            // Delay between retries in seconds (0 for immediate)
        
        // Message handling
        'auto_delete' => true,          // Auto-delete successfully processed messages
        'error_handler' => null,        // Custom error handler
        'shutdown_timeout' => 30,       // Graceful shutdown timeout (seconds)
        
        // DLQ monitoring
        'log_dlq_moves' => true,        // Log when messages are moved to DLQ
        'wait_time_seconds' => 20,
    ];

    /**
     * Create a new SQS FIFO Worker instance
     *
     * @param SqsFifoQueue $queue The queue to process messages from
     * @param array $config Worker configuration
     *   - polling_type: 'long' or 'short' polling
     *   - max_messages: Max messages per batch (1-10)
     *   - wait_time: Wait time for long polling (1-20 seconds)
     *   - visibility_timeout: Message visibility timeout (0-43200 seconds)
     *   - concurrency: Max concurrent message processing
     *   - max_retries: Max retry attempts before DLQ
     *   - retry_delay: Delay between retries in seconds
     *   - auto_delete: Auto-delete processed messages
     *   - error_handler: Custom error handler callback
     *   - shutdown_timeout: Graceful shutdown timeout
     *   - log_dlq_moves: Log when messages are moved to DLQ
     */
    public function __construct(SqsFifoQueue $queue, array $config = [])
    {
        $this->queue = $queue;
        $this->config = array_merge($this->defaultConfig, $config);
        
        // Validate DLQ is properly configured if max_retries is set
        if ($this->config['max_retries'] > 0 && !$queue->getDlqUrl()) {
            error_log('Warning: max_retries is set but no DLQ is configured for this queue. ' .
                     'Failed messages will be retried but never moved to DLQ.');
        }
    }

    /**
     * Get the DLQ instance for this worker's queue
     * 
     * @return SqsFifoQueue|null The DLQ instance or null if not configured
     */
    public function getDlq()
    {
        return $this->queue->getDlq();
    }
    
    /**
     * Process messages from the DLQ
     * 
     * @param callable $processor Callback to process each message
     * @param int $maxMessages Maximum number of messages to process (1-10)
     * @return array Processed messages with their receipt handles
     * @throws \RuntimeException If no DLQ is configured
     */
    public function processDlq(callable $processor, $maxMessages = 10)
    {
        $dlq = $this->getDlq();
        if (!$dlq) {
            throw new \RuntimeException('No DLQ is configured for this queue');
        }
        
        $messages = $dlq->receiveMessages($maxMessages);
        $processed = [];
        
        foreach ($messages as $message) {
            try {
                $result = $processor($message);
                if ($result !== false) {
                    $dlq->deleteMessage($message['ReceiptHandle']);
                    $processed[] = [
                        'message' => $message,
                        'success' => true
                    ];
                } else {
                    $processed[] = [
                        'message' => $message,
                        'success' => false,
                        'error' => 'Processor returned false'
                    ];
                }
            } catch (\Exception $e) {
                $processed[] = [
                    'message' => $message,
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }
        
        return $processed;
    }
    
    /**
     * Start processing messages from the queue
     *
     * @param callable $processor Callback to process each message
     * @return void
     */
    public function start(callable $processor)
    {
        $this->registerSignalHandlers();
        
        echo sprintf(
            "Starting worker with config: %s\n",
            json_encode($this->config, JSON_PRETTY_PRINT)
        );

        while (true) {
            try {
                $this->processNextBatch($processor);
            } catch (\Exception $e) {
                $this->handleError($e);
                sleep(5); // Prevent tight loop on errors
            }
        }
    }

    /**
     * Process a single batch of messages
     */
    private function processNextBatch(callable $processor)
    {
        $messages = $this->queue->receiveMessages(
            $this->config['max_messages'],
            $this->config['wait_time'],
            $this->config['visibility_timeout']
           
        );

        if (empty($messages)) {
            return;
        }

        foreach ($messages as $message) {
           
            try {
                $result = $processor($message);
                
                if ($result !== false && $this->config['auto_delete']) {
                    $this->queue->deleteMessage($message['ReceiptHandle']);
                }
            } catch (\Exception $e) {
                $this->handleError($e, $message, $this->config);
                
                // Check if we should retry
                $receiveCount = $message['Attributes']['ApproximateReceiveCount'] ?? 1;
                if ($receiveCount >= $this->config['max_retries']) {
                    // Move to DLQ or handle final failure
                    $this->handleFinalFailure($e, $message);
                    
                    if ($this->config['auto_delete']) {
                        $this->queue->deleteMessage($message['ReceiptHandle']);
                    }
                }
            }
        }
    }

    /**
     * Handle processing errors and track retries
     */
    private function handleError($exception, $message = null, $config)
    {
        $receiveCount = $message['Attributes']['ApproximateReceiveCount'] ?? 1;
        $maxRetries = $config['max_retries'];
        $isDlqBound = $this->queue->getDlqUrl() !== null;
        
        $errorContext = [
            'error' => $exception->getMessage(),
            'code' => $exception->getCode(),
            'message_id' => $message['MessageId'] ?? null,
            'retry_attempt' => $receiveCount,
            'max_retries' => $maxRetries,
            'dlq_configured' => $isDlqBound,
            'moved_to_dlq' => $isDlqBound && ($receiveCount > $maxRetries)
        ];
        
        // Log DLQ move if it happened
        if ($errorContext['moved_to_dlq'] && $config['log_dlq_moves']) {
            error_log(sprintf(
                'Message %s moved to DLQ after %d attempts: %s',
                $errorContext['message_id'],
                $receiveCount,
                $exception->getMessage()
            ));
        }
        
        // Call custom error handler if provided
        if (is_callable($config['error_handler'])) {
            call_user_func($config['error_handler'], $exception, $message, $errorContext);
        } else {
            error_log('SQS Worker Error: ' . json_encode($errorContext, JSON_PRETTY_PRINT));
        }
        
        // If we've exceeded max retries and have a DLQ, the message will be moved automatically
        // by SQS. We just need to delete it from the main queue.
        if ($errorContext['moved_to_dlq'] && $config['auto_delete'] && isset($message['ReceiptHandle'])) {
            try {
                $this->queue->deleteMessage($message['ReceiptHandle']);
            } catch (\Exception $e) {
                error_log('Failed to delete message after DLQ move: ' . $e->getMessage());
            }
        }
    }

    /**
     * Handle messages that have exceeded max retries
     */
    private function handleFinalFailure(\Exception $e, array $message)
    {
        error_log(sprintf(
            'Message %s failed after %d attempts: %s',
            $message['MessageId'],
            $this->config['max_retries'],
            $e->getMessage()
        ));
        
        // Here you could implement DLQ logic if needed
    }

    /**
     * Register signal handlers for graceful shutdown
     */
    private function registerSignalHandlers()
    {
        if (extension_loaded('pcntl')) {
            pcntl_async_signals(true);
            
            pcntl_signal(SIGTERM, [$this, 'shutdown']);
            pcntl_signal(SIGINT, [$this, 'shutdown']);
            
            register_shutdown_function([$this, 'shutdown']);
        }
    }

    /**
     * Graceful shutdown handler
     */
    public function shutdown()
    {
        echo "Shutting down worker...\n";
        exit(0);
    }
}