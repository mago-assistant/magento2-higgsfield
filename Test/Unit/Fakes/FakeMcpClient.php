<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Higgsfield\Test\Unit\Fakes;

use MagoAssistant\Higgsfield\Service\Client\McpClient;

/**
 * Answers tool calls from canned outputs per tool and records what was called.
 */
class FakeMcpClient extends McpClient
{
    /**
     * @var list<array{name:string,arguments:array<string,mixed>}>
     */
    public array $calls = [];

    /**
     * @param array<string,array<string,mixed>> $outputs Tool output per tool name
     */
    public function __construct(private array $outputs = [])
    {
    }

    /**
     * @param string $tool
     * @param array<string,mixed> $output
     * @return $this
     */
    public function withOutput(string $tool, array $output): self
    {
        $this->outputs[$tool] = $output;

        return $this;
    }

    public function isConnected(): bool
    {
        return true;
    }

    public function callTool(string $name, array $arguments): array
    {
        $this->calls[] = ['name' => $name, 'arguments' => $arguments];

        return $this->outputs[$name] ?? ['error' => 'No output for ' . $name];
    }
}
