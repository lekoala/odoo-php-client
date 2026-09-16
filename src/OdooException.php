<?php

namespace LeKoala\OdooClient;

use Exception;

class OdooException extends Exception
{
    private string $odooName;
    private string $odooMessage;

    /** @var array<int|string, mixed> */
    private array $odooArguments;

    /**
     * @param string $message
     * @param int $code
     * @param \Throwable|null $previous
     * @param string $odooName
     * @param string $odooMessage
     * @param array<int|string, mixed> $odooArguments
     */
    public function __construct(
        string $message = "",
        int $code = 0,
        ?\Throwable $previous = null,
        string $odooName = '',
        string $odooMessage = '',
        array $odooArguments = []
    )
    {
        parent::__construct($message, $code, $previous);

        $this->odooName = $odooName;
        $this->odooMessage = $odooMessage;
        $this->odooArguments = $odooArguments;
    }

    public function getOdooName(): string
    {
        return $this->odooName;
    }

    public function getOdooMessage(): string
    {
        return $this->odooMessage !== '' ? $this->odooMessage : $this->getMessage();
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getOdooArguments(): array
    {
        return $this->odooArguments;
    }

    public function hasOdooError(): bool
    {
        return $this->odooName !== '';
    }
}
