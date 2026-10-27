<?php

namespace App\Mcp\Servers;

use App\Mcp\Tools\ListFilesTool;
use App\Mcp\Tools\ReadFileTool;
use App\Mcp\Tools\SearchFilesTool;
use App\Mcp\Tools\SystemInfoTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Instructions;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('My MCP Server')]
#[Version('0.1.0-alpha.1')]
#[Instructions('Provides system information and read-only file access.')]
class MyMcpServer extends Server
{
    protected array $tools = [
        SystemInfoTool::class,
        ListFilesTool::class,
        ReadFileTool::class,
        SearchFilesTool::class,
    ];

    protected array $resources = [];

    protected array $prompts = [];
}
