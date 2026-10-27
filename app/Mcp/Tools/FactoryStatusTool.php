<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Services\FactorySimulator;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('factory-status')]
#[Description('Read jobs, machines, conveyors, alerts and production totals from the LOCAL FactoryFlow AI simulation. Does not modify production controls.')]
final class FactoryStatusTool extends Tool
{
    public function handle(Request $request, FactorySimulator $factory): Response
    {
        return Response::text(json_encode($factory->snapshot(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
    }
}
