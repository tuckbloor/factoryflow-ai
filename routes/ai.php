<?php

use App\Mcp\Servers\MyMcpServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', MyMcpServer::class);

// Independent FactoryFlow AI simulation tools; original /mcp remains unchanged.
Mcp::web('/factory/mcp', \App\Mcp\Servers\FactoryMcpServer::class);

