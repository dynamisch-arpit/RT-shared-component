<?php

namespace RT\SharedComponents\Exceptions;

class DatabaseConfigException extends \RuntimeException
{
    /**
     * @var string Client ID associated with the error
     */
    private $clientId;

    /**
     * @var array Additional context data
     */
    private $context;

    /**
     * Create a new database configuration exception
     *
     * @param string $message
     * @param string $clientId
     * @param array $context
     * @param int $code
     * @param \Throwable|null $previous
     */
    public function __construct(
        string $message = "",
        string $clientId = null,
        array $context = [],
        int $code = 0,
        \Throwable $previous = null
    ) {
        $this->clientId = $clientId;
        $this->context = $context;
        
        if ($clientId) {
            $message = "[Client: {$clientId}] {$message}";
        }
        
        parent::__construct($message, $code, $previous);
    }

    /**
     * Get the client ID associated with this exception
     *
     * @return string|null
     */
    public function getClientId(): ?string
    {
        return $this->clientId;
    }

    /**
     * Get additional context data
     *
     * @return array
     */
    public function getContext(): array
    {
        return $this->context;
    }
}
