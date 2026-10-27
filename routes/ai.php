<?php

use App\Mcp\Servers\FactoryMcpServer;
use App\Mcp\Servers\MyMcpServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', MyMcpServer::class);

// Independent FactoryFlow AI simulation tools; original /mcp remains unchanged.
Mcp::web('/factory/mcp', FactoryMcpServer::class);
