<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\FactoryActionTool;
use App\Mcp\Tools\FactoryStatusTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('FactoryFlow AI Simulation')]
#[Version('0.1.0')]
#[Instructions('Control only the LOCAL simulated FactoryFlow AI factory. Never represent equipment here as physical machinery. Use factory-status to inspect state before factory-action. Explain planned changes and obtain approval for emergency stops or fault injections. All tool actions affect the on-screen simulator.')]
final class FactoryMcpServer extends Server
{
    protected array $tools = [
        FactoryStatusTool::class,
        FactoryActionTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
