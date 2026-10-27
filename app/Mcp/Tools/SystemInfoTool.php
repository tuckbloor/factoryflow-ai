<?php

namespace App\Mcp\Tools;

use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Returns information about this MCP server and its software versions.')]
class SystemInfoTool extends Tool
{
    public function handle(Request $request): Response
    {
        $information = [
            'server' => 'My Laravel MCP Server',
            'status' => 'running',
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'message' => 'Your MCP server is working!',
        ];

        return Response::text(
            json_encode($information, JSON_PRETTY_PRINT)
        );
    }
}
